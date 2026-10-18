<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Services\AttributionEngine;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;

/** Domain operations for the Phase 3 clock; no timer or process lifecycle here. */
final class PaymentTimingService
{
    public function __construct(private PaymentTransitionService $transitions, private SlotQueueService $queue, private AttributionEngine $attribution) {}

    public function paymentDone(string $paymentId, Actor $actor): PaymentSession
    {
        return DB::transaction(function () use ($paymentId, $actor): PaymentSession {
            $payment = $this->lock($paymentId);
            if ($actor->merchantId !== null && $actor->merchantId !== $payment->merchant_id) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            if ($payment->status === PaymentStatus::PROCESSING || $payment->status->isTerminal()) {
                return $payment;
            }

            return $this->transitions->transition($paymentId, PaymentStatus::PROCESSING, $actor);
        }, 5);
    }

    public function expireIfDue(string $paymentId): PaymentSession
    {
        return DB::transaction(function () use ($paymentId): PaymentSession {
            $payment = $this->lock($paymentId);
            $deadline = match ($payment->status) {
                PaymentStatus::ACTIVE => $payment->expires_at,
                PaymentStatus::PROCESSING => $payment->processing_expires_at,
                default => null,
            };
            if ($deadline !== null && $deadline->lessThanOrEqualTo(now())) {
                if ($payment->verification_grace_expires_at !== null && $payment->verification_grace_expires_at->lessThanOrEqualTo($deadline)) {
                    $this->attribution->finalizeLevel1($paymentId);
                    $payment->refresh();
                    if ($payment->status->isTerminal()) {
                        return $payment;
                    }
                }

                return $this->transitions->transition($paymentId, $payment->status === PaymentStatus::PROCESSING ? PaymentStatus::FAILED : PaymentStatus::EXPIRED, new Actor(ActorType::SYSTEM));
            }

            return $payment;
        }, 5);
    }

    /** Recover the current merchant's overdue sessions and abandoned slots. */
    public function recover(): void
    {
        PaymentSession::whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->orderBy('id')->eachById(function (PaymentSession $payment): void {
            $this->expireIfDue($payment->id);
        });
        PaymentReceivingAccount::orderBy('id')->eachById(function (PaymentReceivingAccount $account): void {
            $this->queue->promoteNext($account->id);
        });
    }

    private function lock(string $paymentId): PaymentSession
    {
        $snapshot = PaymentSession::find($paymentId) ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
        if ($snapshot->receiving_account_id !== null) {
            PaymentReceivingAccount::whereKey($snapshot->receiving_account_id)->lockForUpdate()->first();
        }
        $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
        if ($payment->receiving_account_id !== $snapshot->receiving_account_id) {
            throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
        }

        return $payment;
    }
}
