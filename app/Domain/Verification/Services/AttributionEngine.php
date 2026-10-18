<?php

declare(strict_types=1);

namespace App\Domain\Verification\Services;

use App\Domain\Observations\Data\NormalizedObservation;
use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Verification\Data\AttributionResult;
use App\Domain\Verification\Events\PaymentLateDetected;
use App\Domain\Verification\Events\PaymentLevel1Verified;
use App\Domain\Verification\Events\PaymentVerificationUpgraded;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Models\AuditLog;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Trusted domain entry point. Raw observer input must pass VerificationPipeline first. */
final class AttributionEngine
{
    public function __construct(private PaymentTransitionService $transitions) {}

    public function process(NormalizedObservation $input, ?string $reviewReason = null): AttributionResult
    {
        return DB::transaction(function () use ($input, $reviewReason): AttributionResult {
            $account = PaymentReceivingAccount::whereKey($input->receivingAccountId)->lockForUpdate()->first();
            if ($account === null || $input->amountPaise <= 0) {
                throw new ValidationException(ErrorCode::VALIDATION_FAILED);
            }
            $matches = PaymentObservation::where('receiving_account_id', $account->id)->where('fingerprint_hash', $input->fingerprintHash)->lockForUpdate()->get();
            $duplicate = $matches->first(fn ($observation) => $observation->source === $input->source);
            if ($duplicate !== null) {
                $this->audit($account->merchant_id, $duplicate->id, 'DUPLICATE_OBSERVATION_DROPPED', ['source' => $input->source->value], 'PAYMENT_OBSERVATION');

                return new AttributionResult(false, $duplicate->id, 'DUPLICATE_IGNORED', $duplicate->attributed_payment_id, duplicate: true);
            }
            $observation = PaymentObservation::create([
                'merchant_id' => $account->merchant_id, 'receiving_account_id' => $account->id, 'source' => $input->source,
                'amount_paise' => $input->amountPaise, 'observed_at' => $input->observedAt, 'ingested_at' => now(),
                'sender_vpa' => $input->senderVpa, 'bank_reference_number' => $input->bankReferenceNumber,
                'fingerprint_hash' => $input->fingerprintHash, 'raw_payload' => $input->rawPayload,
                'attribution_status' => AttributionStatus::UNATTRIBUTED,
            ]);

            return $reviewReason === null
                ? $this->attribute($input, $observation, $account, $matches)
                : $this->holdForReview($observation, $reviewReason);
        }, 5);
    }

    public function reattribute(string $observationId): void
    {
        DB::transaction(function () use ($observationId): void {
            $snapshot = PaymentObservation::find($observationId);
            if ($snapshot === null) {
                return;
            }
            $account = PaymentReceivingAccount::whereKey($snapshot->receiving_account_id)->lockForUpdate()->first();
            $observation = PaymentObservation::whereKey($observationId)->lockForUpdate()->first();
            if ($account === null || $observation === null || $observation->attribution_status !== AttributionStatus::UNATTRIBUTED || $observation->verified_at === null) {
                return;
            }
            $input = new NormalizedObservation($account->id, $observation->source, $observation->amount_paise,
                $observation->observed_at, $observation->bank_reference_number, $observation->sender_vpa,
                $observation->fingerprint_hash, $observation->raw_payload ?? []);
            $matches = PaymentObservation::where('receiving_account_id', $account->id)->where('fingerprint_hash', $input->fingerprintHash)->where('id', '!=', $observation->id)->lockForUpdate()->get();
            $this->attribute($input, $observation, $account, $matches);
        }, 5);
    }

    /** @param Collection<int, PaymentObservation> $matches */
    private function attribute(NormalizedObservation $input, PaymentObservation $observation, PaymentReceivingAccount $account, Collection $matches): AttributionResult
    {
        $targetId = $input->rawPayload['targetSessionId'] ?? null;
        $prior = $matches->first(fn ($item) => $item->attributed_payment_id !== null);
        if ($prior !== null) {
            $candidate = $this->sessions($account->id)->whereKey($prior->attributed_payment_id)->lockForUpdate()->first();
        } elseif (is_string($targetId)) {
            $candidate = $this->sessions($account->id)->whereKey($targetId)->lockForUpdate()->first();
        } else {
            $candidate = $this->sessions($account->id)->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->where('amount_paise', $input->amountPaise)->lockForUpdate()->first();
        }
        if (is_string($targetId) && ($candidate === null || $candidate->id !== $targetId)) {
            return new AttributionResult(false, $observation->id, 'UNATTRIBUTED');
        }
        $late = $this->sessions($account->id)->whereIn('status', [PaymentStatus::EXPIRED, PaymentStatus::FAILED, PaymentStatus::CANCELLED])
            ->where('amount_paise', $input->amountPaise)->where('completed_at', '>=', now()->subMinutes(config('paykr.payments.late_observation_window_minutes'))->format('Y-m-d H:i:s.v'))
            ->orderByDesc('completed_at')->lockForUpdate()->first();
        // A fresh reference does not bind a delayed bank message to a checkout.
        // Keep held evidence held across channels. Do not use the reconciliation lookback
        // as evidence that a bank can no longer deliver an older credit.
        if ($matches->contains(fn ($match) => isset($match->raw_payload['reviewReason']))
            || ($candidate !== null && in_array($candidate->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)
                && $input->observedAt->greaterThanOrEqualTo($candidate->slot_acquired_at ?? $candidate->created_at)
                && $this->sessions($account->id)->whereIn('status', [PaymentStatus::EXPIRED, PaymentStatus::FAILED, PaymentStatus::CANCELLED])
                    ->where('amount_paise', $input->amountPaise)->where('created_at', '<=', $input->observedAt)->exists())) {
            return $this->holdForReview($observation, 'AMBIGUOUS_LATE_CREDIT');
        }
        if ($candidate !== null && $candidate->amount_paise === $input->amountPaise) {
            $start = $candidate->slot_acquired_at ?? $candidate->created_at;
            if (in_array($candidate->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)) {
                if ($input->observedAt->lessThan($start) && $late !== null) {
                    return $this->late($late, $observation);
                }
                if ($input->observedAt->greaterThanOrEqualTo($start->subSeconds(300)) && ! $this->conflictingReference($candidate, $observation)) {
                    return $this->verify($candidate, $observation);
                }
            } elseif ($candidate->status === PaymentStatus::SUCCESS && $this->canUpgrade($candidate, $observation)) {
                return $this->verify($candidate, $observation);
            } elseif ($candidate->status !== PaymentStatus::SUCCESS && $candidate->completed_at !== null && $candidate->completed_at->greaterThanOrEqualTo(now()->subMinutes(config('paykr.payments.late_observation_window_minutes')))) {
                return $this->late($candidate, $observation);
            }
        }
        if ($candidate === null && ! is_string($targetId)) {
            $successes = $this->sessions($account->id)->where('status', PaymentStatus::SUCCESS)->where('amount_paise', $input->amountPaise)
                ->where('completed_at', '>=', now()->subSeconds(config('paykr.payments.verification_upgrade_seconds'))->format('Y-m-d H:i:s.v'))
                ->orderByDesc('completed_at')->lockForUpdate()->get();
            foreach ($successes as $success) {
                if ($this->canUpgrade($success, $observation)) {
                    return $this->verify($success, $observation);
                }
            }
            if ($late !== null) {
                return $this->late($late, $observation);
            }
        }

        return new AttributionResult(false, $observation->id, 'UNATTRIBUTED');
    }

    public function finalizeLevel1(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId): void {
            $snapshot = PaymentSession::find($paymentId);
            if ($snapshot === null || $snapshot->receiving_account_id === null) {
                return;
            }
            PaymentReceivingAccount::whereKey($snapshot->receiving_account_id)->lockForUpdate()->first();
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment === null || $payment->verification_grace_expires_at === null || $payment->verification_grace_expires_at->isFuture()) {
                return;
            }
            $deadline = $payment->status === PaymentStatus::PROCESSING ? $payment->processing_expires_at : $payment->expires_at;
            if (! $payment->status->isTerminal() && $deadline !== null && $deadline->lessThan($payment->verification_grace_expires_at) && $deadline->lessThanOrEqualTo(now())) {
                $payment = $this->transitions->transition($payment->id, $payment->status === PaymentStatus::PROCESSING ? PaymentStatus::FAILED : PaymentStatus::EXPIRED, new Actor(ActorType::SYSTEM));
            }
            $observation = $payment->attributedObservation;
            $payment->update(['verification_grace_expires_at' => null]);
            if ($observation === null || $payment->status === PaymentStatus::SUCCESS) {
                return;
            }
            if ($payment->status->isTerminal()) {
                $this->late($payment, $observation);

                return;
            }
            if (in_array($payment->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)) {
                $this->transitions->transition($payment->id, PaymentStatus::SUCCESS, new Actor(ActorType::SYSTEM));
                $this->audit($payment->merchant_id, $payment->id, 'PAYMENT_ATTRIBUTED_SUCCESS_L1', ['observationId' => $observation->id]);
            }
        }, 5);
    }

    /** @return Builder<PaymentSession> */
    private function sessions(string $accountId): Builder
    {
        return PaymentSession::where('receiving_account_id', $accountId)->where('method', 'UPI');
    }

    private function conflictingReference(PaymentSession $payment, PaymentObservation $observation): bool
    {
        $previous = $payment->attributedObservation?->bank_reference_number;

        return $previous !== null && $observation->bank_reference_number !== null && strtoupper($previous) !== strtoupper($observation->bank_reference_number);
    }

    private function canUpgrade(PaymentSession $payment, PaymentObservation $observation): bool
    {
        return $payment->completed_at !== null
            && $payment->completed_at->greaterThanOrEqualTo(now()->subSeconds(config('paykr.payments.verification_upgrade_seconds')))
            && $observation->observed_at->greaterThanOrEqualTo(($payment->slot_acquired_at ?? $payment->created_at)->subSeconds(30))
            && $observation->observed_at->lessThanOrEqualTo($payment->completed_at->addSeconds(30))
            && ! $this->conflictingReference($payment, $observation);
    }

    private function verify(PaymentSession $payment, PaymentObservation $observation): AttributionResult
    {
        $sources = $payment->verification_sources ?? [];
        $newSource = ! in_array($observation->source->value, $sources, true);
        if ($newSource) {
            $sources[] = $observation->source->value;
        }
        $level = min(2, count($sources));
        $labels = array_map(static fn (string $source): string => match ($source) {
            'GOOGLE_MESSAGES' => 'SMS', 'GMAIL' => 'Gmail', default => $source
        }, $sources);
        $tag = ($level > 1 ? 'Dual' : 'Single').' Verification ('.implode(' + ', $labels).')';
        $alreadySucceeded = $payment->status === PaymentStatus::SUCCESS;
        $hadVerification = $payment->verification_level > 0;
        $payment->fill(['verification_sources' => $sources, 'verification_level' => $level, 'verification_tag' => $tag]);
        $payment->attributed_observation_id ??= $observation->id;
        $observation->update(['attribution_status' => AttributionStatus::ATTRIBUTED, 'attributed_payment_id' => $payment->id]);
        $grace = max(0, (int) config('paykr.payments.grace_period_ms'));
        if (! $alreadySucceeded && ! $hadVerification) {
            $payment->verification_grace_expires_at = now()->toImmutable()->addMilliseconds($grace);
        }
        $finalize = ! $alreadySucceeded && (($hadVerification && $newSource) || $grace === 0);
        if ($finalize) {
            $payment->verification_grace_expires_at = null;
        }
        $payment->save();
        if ($finalize) {
            $payment = $this->transitions->transition($payment->id, PaymentStatus::SUCCESS, new Actor(ActorType::SYSTEM));
        } elseif (! $alreadySucceeded && ! $hadVerification) {
            $job = (new FinalizeLevel1Payment($payment->merchant_id, $payment->id))->delay($payment->verification_grace_expires_at?->ceilSecond());
            DB::afterCommit(static function () use ($job): void {
                try {
                    Bus::dispatch($job);
                } catch (Throwable) {
                    Log::warning('Verification grace enqueue failed; persisted deadline awaits recovery.', ['payment_id' => $job->paymentId]);
                }
            });
        }
        $action = $hadVerification ? 'PAYMENT_VERIFICATION_UPGRADED' : 'PAYMENT_LEVEL1_VERIFIED';
        $this->audit($payment->merchant_id, $payment->id, $action, ['observationId' => $observation->id, 'sources' => $sources, 'verificationLevel' => $level]);
        $eventClass = $hadVerification ? PaymentVerificationUpgraded::class : PaymentLevel1Verified::class;
        $event = new $eventClass($payment->merchant_id, $payment->id, $observation->id, $level, $tag);
        DB::afterCommit(static fn () => event($event));

        return new AttributionResult(true, $observation->id, $payment->status === PaymentStatus::SUCCESS ? 'SUCCESS' : 'VERIFYING', $payment->id, verificationLevel: $level, verificationTag: $tag, verificationSources: $sources);
    }

    private function holdForReview(PaymentObservation $observation, string $reason): AttributionResult
    {
        $observation->update(['attribution_status' => AttributionStatus::LATE_UNMATCHED,
            'attributed_payment_id' => null,
            'raw_payload' => array_merge($observation->raw_payload ?? [], ['reviewReason' => $reason])]);
        $this->audit($observation->merchant_id, $observation->id, 'CREDIT_REVIEW_REQUIRED', ['reason' => $reason], 'PAYMENT_OBSERVATION');

        return new AttributionResult(false, $observation->id, 'NEEDS_REVIEW');
    }

    private function late(PaymentSession $payment, PaymentObservation $observation): AttributionResult
    {
        if ($observation->attribution_status !== AttributionStatus::LATE_UNMATCHED) {
            $observation->update(['attribution_status' => AttributionStatus::LATE_UNMATCHED, 'attributed_payment_id' => $payment->id]);
            $this->audit($payment->merchant_id, $payment->id, 'LATE_OBSERVATION_DETECTED', ['observationId' => $observation->id]);
            if ($payment->customer_id !== null && ! SupportTicket::where('payment_id', $payment->id)->where('subject', '[LATE PAYMENT DETECTED] Order #'.$payment->order_id)->exists()) {
                $ticket = SupportTicket::create(['merchant_id' => $payment->merchant_id, 'customer_id' => $payment->customer_id, 'payment_id' => $payment->id, 'subject' => '[LATE PAYMENT DETECTED] Order #'.$payment->order_id, 'status' => 'OPEN']);
                TicketMessage::create(['merchant_id' => $payment->merchant_id, 'ticket_id' => $ticket->id, 'sender_type' => 'ADMIN', 'sender_id' => $payment->merchant_id,
                    'message' => 'A payment of '.(new Money($payment->amount_paise))->formatINR().' was received after order #'.$payment->order_id.' had already ended ('.$payment->status->value.'). The order was NOT marked paid. Please refund the customer, or fulfil the order manually if you confirm the money arrived.']);
            }
            $event = new PaymentLateDetected($payment->merchant_id, $payment->id, $observation->id, $payment->verification_level, $payment->verification_tag);
            DB::afterCommit(static fn () => event($event));
        }

        return new AttributionResult(false, $observation->id, 'LATE_UNMATCHED', $payment->id, lateMatch: true);
    }

    /** @param array<string, mixed> $details */
    private function audit(string $merchantId, string $id, string $action, array $details, string $entityType = 'PAYMENT_SESSION'): void
    {
        AuditLog::create(['merchant_id' => $merchantId, 'entity_type' => $entityType, 'entity_id' => $id, 'action' => $action, 'actor_type' => 'SYSTEM', 'details' => $details]);
    }
}
