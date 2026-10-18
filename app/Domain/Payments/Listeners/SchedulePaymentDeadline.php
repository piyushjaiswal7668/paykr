<?php

declare(strict_types=1);

namespace App\Domain\Payments\Listeners;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Jobs\ExpireActivePayment;
use App\Domain\Payments\Jobs\FailProcessingPayment;
use App\Domain\Shared\Services\TenantContext;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SchedulePaymentDeadline
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(PaymentStateChanged $event): void
    {
        $this->tenants->run($event->merchantId, function () use ($event): void {
            $payment = PaymentSession::find($event->paymentId);
            if ($payment !== null) {
                $this->schedule($payment);
            }
        });
    }

    public function schedule(PaymentSession $payment): bool
    {
        $job = match ($payment->status) {
            PaymentStatus::ACTIVE => new ExpireActivePayment($payment->merchant_id, $payment->id),
            PaymentStatus::PROCESSING => new FailProcessingPayment($payment->merchant_id, $payment->id),
            default => null,
        };
        $deadline = $payment->status === PaymentStatus::PROCESSING ? $payment->processing_expires_at : $payment->expires_at;
        if ($job === null || $deadline === null) {
            return false;
        }
        try {
            Bus::dispatch($job->delay($deadline->ceilSecond()));

            return true;
        } catch (Throwable) {
            Log::warning('Payment deadline enqueue failed; clock will recover.', ['payment_id' => $payment->id]);

            return false;
        }
    }
}
