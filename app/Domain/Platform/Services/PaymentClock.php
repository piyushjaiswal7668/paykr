<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Observers\Services\ObserverDispatch;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Listeners\SchedulePaymentDeadline;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Domain\Verification\Services\AttributionEngine;
use App\Models\MerchantGmailConnection;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentClock
{
    public function __construct(private TenantContext $tenants, private PaymentTimingService $timing, private SlotQueueService $queue, private SchedulePaymentDeadline $deadlines, private AttributionEngine $attribution, private ObserverDispatch $observers) {}

    /** @return array{expiredCount: int, failedCount: int, rescheduledCount: int, promotedCount: int} */
    public function tick(bool $recover = false, ?\Closure $renew = null): array
    {
        return $this->tenants->runUnscoped(function () use ($recover, $renew): array {
            $counts = ['expiredCount' => 0, 'failedCount' => 0, 'rescheduledCount' => 0, 'promotedCount' => 0];
            $waiting = PaymentSession::where('status', PaymentStatus::QUEUED)->pluck('id');
            // Finalize elapsed verification before expiry when its deadline was earlier.
            PaymentSession::whereNotNull('verification_grace_expires_at')->where('verification_grace_expires_at', '<=', now()->format('Y-m-d H:i:s.v'))->eachById(function (PaymentSession $payment) use ($renew): void {
                $renew?->__invoke();
                $this->tenants->run($payment->merchant_id, fn () => $this->attribution->finalizeLevel1($payment->id));
            });
            PaymentSession::whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->eachById(function (PaymentSession $payment) use (&$counts, $recover, $renew): void {
                $renew?->__invoke();
                $this->tenants->run($payment->merchant_id, function () use ($payment, &$counts, $recover): void {
                    $result = $this->timing->expireIfDue($payment->id);
                    if ($result->status === PaymentStatus::EXPIRED) {
                        $counts['expiredCount']++;
                    } elseif ($result->status === PaymentStatus::FAILED) {
                        $counts['failedCount']++;
                    } elseif ($recover && $this->deadlines->schedule($result)) {
                        $counts['rescheduledCount']++;
                    }
                    if ($recover && ! $result->status->isTerminal() && $result->verification_grace_expires_at !== null && $result->verification_grace_expires_at->isFuture()) {
                        try {
                            Bus::dispatch((new FinalizeLevel1Payment($result->merchant_id, $result->id))->delay($result->verification_grace_expires_at->ceilSecond()));
                            $counts['rescheduledCount']++;
                        } catch (Throwable) {
                            Log::warning('Grace recovery enqueue unavailable; clock retains the deadline.', ['payment_id' => $result->id]);
                        }
                    }
                });
            });
            PaymentReceivingAccount::where('is_active', true)->eachById(function (PaymentReceivingAccount $account) use ($renew): void {
                $renew?->__invoke();
                $this->tenants->run($account->merchant_id, fn () => $this->queue->promoteNext($account->id));
            });
            $active = PaymentSession::where('method', 'UPI')->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->get();
            foreach ($active as $payment) {
                $renew?->__invoke();
                try {
                    $this->tenants->run($payment->merchant_id, fn () => $this->observers->dispatch($payment->merchant_id, $payment->receiving_account_id, $payment->status === PaymentStatus::PROCESSING));
                } catch (Throwable) {
                    Log::warning('Observer dispatch unavailable; clock continues.', ['merchant_id' => $payment->merchant_id]);
                }
            }
            MerchantGmailConnection::whereNotNull('refresh_token')->whereNotIn('merchant_id', $active->pluck('merchant_id'))->eachById(function ($connection) use ($renew): void {
                $renew?->__invoke();
                try {
                    $this->tenants->run($connection->merchant_id, fn () => $this->observers->dispatch($connection->merchant_id, null));
                } catch (Throwable) {
                    Log::warning('Idle Gmail dispatch unavailable.', ['merchant_id' => $connection->merchant_id]);
                }
            });
            $counts['promotedCount'] = PaymentSession::whereIn('id', $waiting)->where('status', PaymentStatus::ACTIVE)->count();

            return $counts;
        });
    }
}
