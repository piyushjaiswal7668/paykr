<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentCancelled;
use App\Domain\Payments\Events\PaymentExpired;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Events\PaymentSucceeded;
use App\Domain\Payments\Events\SessionActivated;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Webhooks\Services\WebhookService;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\MerchantCustomerBlock;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PaymentTransitionService
{
    public function __construct(private WebhookService $webhooks) {}

    public function transition(string $paymentId, PaymentStatus $target, Actor $actor): PaymentSession
    {
        return $this->applyTransition($paymentId, $target, $actor, false);
    }

    /** Only an already persisted, merchant-approved credit can reopen a terminal order. */
    public function settleReviewedCredit(string $paymentId, Actor $actor): PaymentSession
    {
        return $this->applyTransition($paymentId, PaymentStatus::SUCCESS, $actor, true);
    }

    private function applyTransition(string $paymentId, PaymentStatus $target, Actor $actor, bool $reviewed): PaymentSession
    {
        return DB::transaction(function () use ($paymentId, $target, $actor, $reviewed): PaymentSession {
            $snapshot = PaymentSession::find($paymentId);
            if ($snapshot === null) {
                throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            }
            // All writers lock account first, then session. Re-read the session under lock.
            $account = $snapshot->receiving_account_id === null ? null : PaymentReceivingAccount::whereKey($snapshot->receiving_account_id)->lockForUpdate()->first();
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            }
            if ($payment->receiving_account_id !== $snapshot->receiving_account_id) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            if ($actor->merchantId !== null && $actor->merchantId !== $payment->merchant_id) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            $from = $payment->status;
            if ($reviewed) {
                $approval = $payment->attributedObservation?->raw_payload['reviewResolution'] ?? null;
                $user = $actor->id === null ? null : User::find($actor->id);
                if ($actor->type !== ActorType::MERCHANT_USER || $actor->merchantId !== $payment->merchant_id
                    || $user === null || ! $user->merchants()->whereKey($payment->merchant_id)->where('status', 'ACTIVE')->wherePivotIn('role', ['owner', 'manager'])->exists()
                    || ! is_array($approval) || ($approval['approvedBy'] ?? null) !== $actor->id
                    || ($approval['paymentId'] ?? null) !== $paymentId || ($approval['outcome'] ?? null) !== 'APPROVED_PAID'
                    || ($from->isTerminal() && ! ($approval['reopenConfirmed'] ?? false)) || $from === PaymentStatus::SUCCESS) {
                    throw new ValidationException(ErrorCode::FORBIDDEN);
                }
            }
            if ($from->isTerminal() && ! $reviewed) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            if (! $from->canTransitionTo($target, $payment->method) && ! ($reviewed && in_array($from, [PaymentStatus::EXPIRED, PaymentStatus::CANCELLED, PaymentStatus::FAILED], true))) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            if ($payment->customer_id !== null && in_array($target, [PaymentStatus::ACTIVE, PaymentStatus::QUEUED, PaymentStatus::PROCESSING, PaymentStatus::SUCCESS], true)) {
                $customer = Customer::whereKey($payment->customer_id)->lockForUpdate()->first();
                if ($customer?->status === CustomerStatus::BANNED) {
                    throw new ValidationException(ErrorCode::CUSTOMER_BANNED);
                }
                if (MerchantCustomerBlock::where('merchant_id', $payment->merchant_id)->where('customer_id', $payment->customer_id)->exists()) {
                    throw new ValidationException(ErrorCode::CUSTOMER_BLOCKED_BY_MERCHANT);
                }
            }
            $now = now()->toImmutable();
            if ($target === PaymentStatus::PROCESSING && $payment->expires_at !== null && $payment->expires_at->lessThanOrEqualTo($now)) {
                throw new ValidationException(ErrorCode::PAYMENT_EXPIRED);
            }
            if ($payment->method === PaymentMethod::UPI && in_array($target, [PaymentStatus::ACTIVE, PaymentStatus::QUEUED, PaymentStatus::PROCESSING], true)) {
                if ($account === null || $account->merchant_id !== $payment->merchant_id) {
                    throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
                }
                if ($target === PaymentStatus::ACTIVE) {
                    if (! $account->is_active || ! $account->is_merchant_enabled) {
                        throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
                    }
                    // Query live owners as well as the pointer, so a stale flag cannot grant two slots.
                    $ownerExists = PaymentSession::where('receiving_account_id', $account->id)->where('method', PaymentMethod::UPI)
                        ->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->where('id', '!=', $payment->id)->lockForUpdate()->exists();
                    if ($ownerExists) {
                        throw new ValidationException(ErrorCode::RECEIVING_ACCOUNT_BUSY);
                    }
                    $account->update(['is_slot_busy' => true, 'current_active_session_id' => $payment->id]);
                } elseif ($target === PaymentStatus::PROCESSING && $account->current_active_session_id !== $payment->id) {
                    throw new ValidationException(ErrorCode::RECEIVING_ACCOUNT_BUSY);
                }
            }
            $payment->status = $target;
            if ($target === PaymentStatus::QUEUED) {
                $last = PaymentSession::where('receiving_account_id', $payment->receiving_account_id)->where('status', PaymentStatus::QUEUED)
                    ->orderByDesc('queue_position')->lockForUpdate()->first();
                $payment->queue_position = 1 + ($last->queue_position ?? 0);
            } else {
                $payment->queue_position = 0;
            }
            if ($target === PaymentStatus::ACTIVE) {
                $payment->slot_acquired_at = $now;
                $payment->expires_at = in_array($payment->method, [PaymentMethod::CASH_COUNTER, PaymentMethod::CARD_COUNTER], true) ? null : $now->addSeconds(config('paykr.payments.active_window_seconds'));
            }
            if ($target === PaymentStatus::PROCESSING) {
                $payment->processing_started_at = $now;
                $payment->processing_expires_at = $now->addSeconds(config('paykr.payments.processing_window_seconds'));
            }
            if ($target->isTerminal()) {
                $payment->completed_at = $now;
                if ($account !== null && $account->current_active_session_id === $payment->id) {
                    $account->update(['is_slot_busy' => false, 'current_active_session_id' => null]);
                }
            }
            $payment->save();
            if ($target->isTerminal()) {
                $this->webhooks->recordTerminal($payment->id);
            }
            AuditLog::create([
                'merchant_id' => $payment->merchant_id, 'entity_type' => 'PAYMENT_SESSION', 'entity_id' => $payment->id,
                'action' => match ($target) {
                    PaymentStatus::ACTIVE => $from === PaymentStatus::QUEUED ? 'QUEUE_PROMOTION_TO_ACTIVE' : 'PAYMENT_SLOT_ACTIVATED',
                    PaymentStatus::QUEUED => 'PAYMENT_ENQUEUED',
                    default => 'PAYMENT_'.$target->value,
                },
                'actor_type' => $actor->type->value, 'actor_id' => $actor->id, 'ip_address' => $actor->ip,
                'details' => ['from' => $from->value, 'to' => $target->value, 'receivingAccountId' => $payment->receiving_account_id, 'merchantReviewed' => $reviewed],
            ]);
            $class = match ($target) {
                PaymentStatus::SUCCESS => PaymentSucceeded::class,
                PaymentStatus::FAILED => PaymentFailed::class,
                PaymentStatus::EXPIRED => PaymentExpired::class,
                PaymentStatus::CANCELLED => PaymentCancelled::class,
                PaymentStatus::ACTIVE => SessionActivated::class,
                default => PaymentStateChanged::class,
            };
            $event = new $class($payment->merchant_id, $payment->id, $payment->receiving_account_id, $from, $target);
            DB::afterCommit(static fn () => event($event));

            return $payment;
        }, 5);
    }
}
