<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\QueueUpdated;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;

final class SlotQueueService
{
    public function __construct(private PaymentTransitionService $transitions) {}

    public function enqueueOrActivate(string $paymentId, string $accountId, Actor $actor): PaymentSession
    {
        return DB::transaction(function () use ($paymentId, $accountId, $actor): PaymentSession {
            $account = $this->lockAccount($accountId);
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            }
            if ($payment->receiving_account_id !== $account->id || $payment->merchant_id !== $account->merchant_id || ($actor->merchantId !== null && $actor->merchantId !== $payment->merchant_id)) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            if ($payment->method !== PaymentMethod::UPI) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            if ($payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            if (! $account->is_active || ! $account->is_merchant_enabled) {
                throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
            }
            $owner = $this->liveOwner($account);
            $payment->refresh();
            if ($payment->status->isTerminal()) {
                // Its deadline elapsed while checking the current owner; persist that closure.
                return $payment;
            }
            if ($owner?->id === $payment->id) {
                return $payment;
            }
            if ($owner === null) {
                // A newly arriving request must not jump an existing queue.
                $next = $this->nextQueued($accountId);
                $owner = $this->transitions->transition($next->id ?? $payment->id, PaymentStatus::ACTIVE, $next === null ? $actor : new Actor(ActorType::SYSTEM));
            }
            if ($owner->id !== $payment->id && $payment->status === PaymentStatus::INITIATED) {
                $payment = $this->transitions->transition($payment->id, PaymentStatus::QUEUED, $actor);
            }
            $this->reindex($account);

            return $payment->refresh();
        }, 5);
    }

    public function promoteNext(string $accountId): ?PaymentSession
    {
        return DB::transaction(function () use ($accountId): ?PaymentSession {
            $account = $this->lockAccount($accountId);
            $owner = $this->liveOwner($account);
            $next = null;
            if ($owner === null && $account->is_active && $account->is_merchant_enabled) {
                $waiting = $this->nextQueued($accountId);
                if ($waiting !== null) {
                    $next = $this->transitions->transition($waiting->id, PaymentStatus::ACTIVE, new Actor(ActorType::SYSTEM));
                }
            }
            $this->reindex($account);

            return $next;
        }, 5);
    }

    public function estimatedWaitSeconds(string $accountId, int $queuePosition): int
    {
        if ($queuePosition <= 0) {
            return 0;
        }
        $owner = PaymentSession::where('receiving_account_id', $accountId)->where('method', PaymentMethod::UPI)
            ->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->first();
        $deadline = $owner?->status === PaymentStatus::PROCESSING ? $owner->processing_expires_at : $owner?->expires_at;
        $remaining = $deadline === null ? 0 : max(0, (int) ceil(now()->diffInSeconds($deadline, false)));

        return $remaining + ($queuePosition - 1) * (config('paykr.payments.active_window_seconds') + config('paykr.payments.processing_window_seconds'));
    }

    private function lockAccount(string $accountId): PaymentReceivingAccount
    {
        $account = PaymentReceivingAccount::whereKey($accountId)->lockForUpdate()->first();
        if ($account === null) {
            throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
        }

        return $account;
    }

    private function liveOwner(PaymentReceivingAccount $account): ?PaymentSession
    {
        $owners = PaymentSession::where('receiving_account_id', $account->id)->where('method', PaymentMethod::UPI)
            ->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $live = null;
        foreach ($owners as $owner) {
            $deadline = $owner->status === PaymentStatus::PROCESSING ? $owner->processing_expires_at : $owner->expires_at;
            if ($deadline !== null && $deadline->lessThanOrEqualTo(now())) {
                $this->transitions->transition($owner->id, $owner->status === PaymentStatus::PROCESSING ? PaymentStatus::FAILED : PaymentStatus::EXPIRED, new Actor(ActorType::SYSTEM));
            } elseif ($live !== null) {
                // Refuse an inconsistent legacy account rather than silently choose a customer.
                throw new ValidationException(ErrorCode::RECEIVING_ACCOUNT_BUSY);
            } else {
                $live = $owner;
            }
        }
        $account->refresh();
        if ($account->current_active_session_id !== $live?->id || $account->is_slot_busy !== ($live !== null)) {
            $account->update(['current_active_session_id' => $live?->id, 'is_slot_busy' => $live !== null]);
        }

        return $live;
    }

    private function nextQueued(string $accountId): ?PaymentSession
    {
        return PaymentSession::where('receiving_account_id', $accountId)->where('method', PaymentMethod::UPI)->where('status', PaymentStatus::QUEUED)
            ->orderBy('queue_position')->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
    }

    private function reindex(PaymentReceivingAccount $account): void
    {
        $waiting = PaymentSession::where('receiving_account_id', $account->id)->where('method', PaymentMethod::UPI)->where('status', PaymentStatus::QUEUED)
            ->orderBy('queue_position')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $positions = [];
        foreach ($waiting as $index => $payment) {
            if ($payment->queue_position !== $index + 1) {
                $payment->update(['queue_position' => $index + 1]);
            }
            $positions[$payment->id] = $index + 1;
        }
        $event = new QueueUpdated($account->merchant_id, $account->id, $positions);
        DB::afterCommit(static fn () => event($event));
    }
}
