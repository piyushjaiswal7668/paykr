<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AuditLog;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApproveHeldCredit
{
    public function handle(string $merchantId, string $userId, string $observationId, string $paymentId, string $reference, string $note, bool $reopenConfirmed = false): string
    {
        $user = User::find($userId);
        if ($user === null || ! $user->merchants()->whereKey($merchantId)->where('status', 'ACTIVE')->wherePivotIn('role', ['owner', 'manager'])->exists()) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        $note = trim($note);
        if (mb_strlen($note) < 10 || mb_strlen($note) > 1000) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }

        return app(TenantContext::class)->run($merchantId, fn (): string => DB::transaction(function () use ($merchantId, $userId, $observationId, $paymentId, $reference, $note, $reopenConfirmed): string {
            $snapshot = PaymentObservation::find($observationId) ?? throw new ValidationException(ErrorCode::NOT_FOUND);
            PaymentReceivingAccount::whereKey($snapshot->receiving_account_id)->lockForUpdate()->firstOrFail();
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            $observation = PaymentObservation::whereKey($observationId)->lockForUpdate()->firstOrFail();
            if ($payment->receiving_account_id !== $observation->receiving_account_id || $payment->amount_paise !== $observation->amount_paise
                || $payment->method->value !== 'UPI' || ! preg_match('/^[0-9]{12}$/D', $reference)
                || $reference !== $observation->bank_reference_number) {
                throw new ValidationException(ErrorCode::VALIDATION_FAILED);
            }
            $resolution = $observation->raw_payload['reviewResolution'] ?? null;
            if (is_array($resolution)) {
                if (($resolution['paymentId'] ?? null) !== $paymentId) {
                    throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
                }

                return (string) $resolution['outcome'];
            }
            if ($observation->attribution_status !== AttributionStatus::LATE_UNMATCHED
                || ($observation->attributed_payment_id !== null && $observation->attributed_payment_id !== $paymentId)
                || ($payment->attributed_observation_id !== null && $payment->attributed_observation_id !== $observationId) || $payment->status === PaymentStatus::SUCCESS) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            // The account lock serializes approvals and automatic attribution across all channels.
            if (PaymentObservation::where('receiving_account_id', $observation->receiving_account_id)->where('bank_reference_number', $reference)
                ->where('id', '!=', $observationId)->where(function ($query): void {
                    $query->where('attribution_status', AttributionStatus::ATTRIBUTED)->orWhereNotNull('raw_payload->reviewResolution');
                })->exists()) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $payment = app(PaymentTimingService::class)->expireIfDue($paymentId);
            $closed = $payment->status->isTerminal();
            if (! $closed && ! in_array($payment->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)) {
                throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
            }
            if ($closed && ! $reopenConfirmed) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            $outcome = 'APPROVED_PAID';
            $observation->update(['attributed_payment_id' => $paymentId,
                'attribution_status' => AttributionStatus::ATTRIBUTED,
                'raw_payload' => array_merge($observation->raw_payload ?? [], ['reviewResolution' => [
                    'outcome' => $outcome, 'paymentId' => $paymentId, 'approvedBy' => $userId, 'approvedAt' => now()->toIso8601String(), 'note' => $note, 'reopenConfirmed' => $reopenConfirmed, 'previousStatus' => $payment->status->value,
                ]])]);

            $payment->update(['attributed_observation_id' => $observationId, 'verification_level' => 1,
                'verification_sources' => [$observation->source->value], 'verification_tag' => 'Merchant reviewed credit', 'verification_grace_expires_at' => null]);
            app(PaymentTransitionService::class)->settleReviewedCredit($paymentId, new Actor(ActorType::MERCHANT_USER, $userId, $merchantId));

            AuditLog::create(['merchant_id' => $merchantId, 'entity_type' => 'PAYMENT_OBSERVATION', 'entity_id' => $observationId,
                'action' => 'HELD_CREDIT_APPROVED', 'actor_type' => ActorType::MERCHANT_USER->value, 'actor_id' => $userId,
                'details' => ['paymentId' => $paymentId, 'outcome' => $outcome, 'note' => $note]]);

            return $outcome;
        }, 5));
    }
}
