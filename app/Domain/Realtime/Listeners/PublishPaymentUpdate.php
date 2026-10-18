<?php

declare(strict_types=1);

namespace App\Domain\Realtime\Listeners;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentActionTriggered;
use App\Domain\Payments\Events\PaymentMethodSwitched;
use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Events\QueueUpdated;
use App\Domain\Payments\Events\SessionActivated;
use App\Domain\Realtime\Events\RealtimeUpdate;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Events\PaymentFraudBlocked;
use App\Domain\Verification\Events\VerificationChanged;
use App\Models\PaymentSession;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublishPaymentUpdate implements ShouldHandleEventsAfterCommit
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(PaymentStateChanged|PaymentMethodSwitched|PaymentActionTriggered|QueueUpdated|VerificationChanged|PaymentFraudBlocked $event): void
    {
        if (in_array(config('broadcasting.default'), [null, 'null'], true)) {
            return;
        }
        try {
            $this->tenants->run($event->merchantId, function () use ($event): void {
                $ids = $event instanceof QueueUpdated ? array_keys($event->positions) : [$event->paymentId];
                foreach (PaymentSession::whereKey($ids)->get() as $payment) {
                    $payload = $this->snapshot($payment, $event instanceof PaymentFraudBlocked);
                    event(new RealtimeUpdate('payment.'.$payment->id, 'PaymentStatusChanged', $payload));
                    event(new RealtimeUpdate('merchant.'.$payment->merchant_id.'.live', 'LivePaymentUpdated', ['paymentId' => $payment->id] + $payload));
                    if ($event instanceof PaymentFraudBlocked) {
                        event(new RealtimeUpdate('payment.'.$payment->id, 'PaymentAccountBlocked', $payload));
                    }
                    if ($event instanceof SessionActivated && $event->from === PaymentStatus::QUEUED) {
                        event(new RealtimeUpdate('merchant.'.$payment->merchant_id.'.live', 'QueuePromoted', ['promotedId' => $payment->id]));
                    }
                    if (($event instanceof SessionActivated || $event instanceof PaymentMethodSwitched) && in_array($payment->method, [PaymentMethod::CASH_COUNTER, PaymentMethod::CARD_COUNTER], true) && $payment->status === PaymentStatus::ACTIVE) {
                        event(new RealtimeUpdate('merchant.'.$payment->merchant_id.'.live', 'CounterPaymentRequested', ['paymentId' => $payment->id] + $payload));
                    }
                }
            });
        } catch (Throwable $exception) {
            // Delivery failure must not turn an already committed payment into an HTTP error.
            Log::warning('Realtime update could not be queued.', ['event' => $event::class, 'exception' => $exception::class]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(PaymentSession $payment, bool $blocked): array
    {
        $deadline = $payment->status === PaymentStatus::PROCESSING ? $payment->processing_expires_at : $payment->expires_at;

        return [
            'status' => $blocked ? 'BLOCKED' : $payment->status->value,
            'queuePosition' => $payment->queue_position,
            'expiresAt' => $payment->expires_at?->toISOString(),
            'processingExpiresAt' => $payment->processing_expires_at?->toISOString(),
            'method' => $payment->method->value,
            'timeRemainingSeconds' => $payment->status->isTerminal() || $deadline === null ? 0 : max(0, (int) ceil(now()->diffInSeconds($deadline, false))),
            'verificationLevel' => $payment->verification_level,
            'verificationTag' => $payment->verification_tag,
            'sources' => $payment->verification_sources ?? [],
            'reason' => $blocked ? 'SUSPICIOUS_PAYMENT_ACTIVITY' : match ($payment->metadata['reason'] ?? null) {
                'REPLACED_ON_ANOTHER_DEVICE' => 'REPLACED_ON_ANOTHER_DEVICE',
                'USER_RETRY_NEW_PAYMENT' => 'USER_RETRY_NEW_PAYMENT',
                default => null,
            },
            'message' => $blocked ? 'Payment access is blocked pending review.' : null,
            'blockedNotice' => $blocked ? ['title' => 'Account blocked', 'message' => 'Please contact support for review.'] : null,
        ];
    }
}
