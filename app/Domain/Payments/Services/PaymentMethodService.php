<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Merchants\Enums\SellingMode;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentMethodSwitched;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AuditLog;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;

final class PaymentMethodService
{
    public function __construct(private PaymentTransitionService $transitions, private SlotQueueService $queue) {}

    public function select(string $paymentId, PaymentMethod $method, Actor $actor): PaymentSession
    {
        return DB::transaction(function () use ($paymentId, $method, $actor): PaymentSession {
            $snapshot = PaymentSession::find($paymentId) ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            $accountId = $snapshot->receiving_account_id;
            $destination = $method === PaymentMethod::UPI ? PaymentReceivingAccount::where('is_active', true)->value('id') : null;
            $accounts = PaymentReceivingAccount::whereIn('id', array_filter([$accountId, $destination]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->receiving_account_id !== $accountId || ($actor->merchantId !== null && $actor->merchantId !== $payment->merchant_id)) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            if ($payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            $settings = MerchantPaymentSettings::first();
            $enabled = match ($method) {
                PaymentMethod::UPI => true,
                PaymentMethod::COD => $settings?->selling_mode === SellingMode::ONLINE && $settings->cod_enabled,
                PaymentMethod::CASH_COUNTER => $settings?->selling_mode === SellingMode::OFFLINE && $settings->cash_enabled,
                PaymentMethod::CARD_COUNTER => $settings?->selling_mode === SellingMode::OFFLINE && $settings->card_enabled,
            };
            if (! $enabled) {
                throw new ValidationException(ErrorCode::PAYMENT_METHOD_DISABLED);
            }
            if ($payment->method === $method) {
                return $payment;
            }
            if ($payment->verification_level > 0) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $from = $payment->method;
            $account = $accountId === null ? null : $accounts->get($accountId);
            if ($account?->current_active_session_id === $payment->id) {
                $account->update(['is_slot_busy' => false, 'current_active_session_id' => null]);
            }
            $payment->update(['method' => $method, 'status' => PaymentStatus::INITIATED, 'receiving_account_id' => $destination,
                'queue_position' => 0, 'expires_at' => null, 'processing_started_at' => null, 'processing_expires_at' => null, 'slot_acquired_at' => null]);
            if ($method === PaymentMethod::UPI) {
                if (! is_string($destination)) {
                    throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
                }
                $payment = $this->queue->enqueueOrActivate($payment->id, $destination, $actor);
            } else {
                $payment = $this->transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
            }
            if ($accountId !== null) {
                $this->queue->promoteNext($accountId);
            }
            AuditLog::create(['merchant_id' => $payment->merchant_id, 'entity_type' => 'PAYMENT_SESSION', 'entity_id' => $payment->id,
                'action' => 'PAYMENT_METHOD_SWITCHED', 'actor_type' => $actor->type->value, 'actor_id' => $actor->id,
                'details' => ['from' => $from->value, 'to' => $method->value]]);
            $event = new PaymentMethodSwitched($payment->merchant_id, $payment->id, $from, $method);
            DB::afterCommit(static fn () => event($event));

            return $payment;
        }, 5);
    }
}
