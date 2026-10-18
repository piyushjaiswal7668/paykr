<?php

declare(strict_types=1);

namespace App\Domain\Verification\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Observations\Data\ObservationData;
use App\Domain\Observations\Enums\ObserverSource;
use App\Domain\Observations\Services\AmountExtractor;
use App\Domain\Observations\Services\ObservationNormalizer;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Data\VerificationResult;
use App\Models\Customer;
use App\Models\MerchantTestMode;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VerificationPipeline
{
    public function __construct(private AttributionEngine $attribution, private FraudService $fraud) {}

    public function process(VerificationData $input): VerificationResult
    {
        return DB::transaction(fn () => $this->verify($input), 5);
    }

    private function verify(VerificationData $input): VerificationResult
    {
        $rawSource = strtoupper($input->source);
        $source = str_contains($rawSource, 'GMAIL') || str_contains($rawSource, 'EMAIL') ? 'GMAIL' : 'SMS';
        $supported = $source === 'GMAIL' || in_array($rawSource, ['SMS', 'SMS_GATEWAY', 'GOOGLE_MESSAGES', 'ANDROID_OBSERVER'], true);
        $sender = $input->sender ?? ($source === 'GMAIL' ? 'alerts@retail.bank.in' : 'BANK-ALERT');
        $at = $input->receivedAt ?? now()->toImmutable();
        $result = [
            'success' => false, 'status' => 'UNRESOLVED', 'source' => $source,
            'messageId' => $input->messageId ?? ($input->isTest ? 'TEST-' : 'OBS-').substr((string) Str::uuid(), 0, 8),
            'receivedAt' => $at->utc()->format('Y-m-d\TH:i:s.v\Z'), 'amount' => null, 'amountPaise' => null, 'reference' => null,
            'sender' => $sender, 'isTest' => $input->isTest, 'raw' => $input->rawText,
            'failingStage' => null, 'failingCondition' => null, 'reason' => null, 'details' => null, 'stages' => [], 'session' => null,
        ];
        foreach (['LOADED', 'FILTER', 'PARSER', 'VERIFICATION', 'SECURITY', 'ATTRIBUTION'] as $i => $name) {
            $result['stages']['stage'.($i + 1)] = ['stage' => $i + 1, 'name' => $name, 'status' => $i === 0 ? 'LOADED' : 'PENDING'];
        }
        $result['stages']['stage1']['details'] = ['messageId' => $result['messageId'], 'source' => $source, 'sender' => $sender, 'receivedAt' => $result['receivedAt'], 'isTest' => $input->isTest, 'rawPreserved' => true];
        $account = PaymentReceivingAccount::whereKey($input->receivingAccountId)->lockForUpdate()->first();
        $session = null;
        if ($account !== null) {
            $sessions = PaymentSession::where('receiving_account_id', $account->id)->where('method', 'UPI');
            $session = $input->paymentSessionId !== null ? $sessions->whereKey($input->paymentSessionId)->lockForUpdate()->first()
                : $sessions->whereIn('status', [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])->lockForUpdate()->first();
        }
        if ($session !== null) {
            $result['session'] = ['id' => $session->id, 'orderId' => $session->order_id, 'amountPaise' => (string) $session->amount_paise, 'status' => $session->status->value];
        }
        $customer = $session?->customer_id !== null ? Customer::whereKey($session->customer_id)->lockForUpdate()->first() : null;
        if ($customer === null && $input->customerId !== null) {
            $customer = Customer::whereKey($input->customerId)->lockForUpdate()->first();
        }
        if ($customer === null && $input->customerId === null) {
            preg_match('/<([^>]+)>/', $sender, $senderEmail);
            $email = strtolower(trim($senderEmail[1] ?? $sender));
            $mobile = substr(preg_replace('/\D/', '', $sender) ?? '', -10);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $customer = Customer::where('email', $email)->lockForUpdate()->first();
            } elseif (preg_match('/^[6-9]\d{9}$/D', $mobile)) {
                $customer = Customer::where('mobile_number', $mobile)->lockForUpdate()->first();
            }
        }
        $attributable = $customer !== null && $this->isCustomerSender($customer, $sender, $input->submissionIdentity);
        $trustedTest = $session !== null && $session->is_test && $input->isTest && $input->testModeActive
            && MerchantTestMode::where('merchant_id', $session->merchant_id)->where('enabled_at', '<=', now()->format('Y-m-d H:i:s.v'))
                ->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->exists();
        if (! $supported) {
            return $this->fail($result, 2, 'IRRELEVANT_SOURCE');
        }
        if (($input->isSimulated || $input->isTest) && ! $trustedTest) {
            return $this->fail($result, 2, 'TEST_MODE_INACTIVE');
        }
        if ($source === 'SMS') {
            $clean = trim($sender);
            $numeric = preg_match('/^\d+$/D', preg_replace('/[\s+\-]/', '', $clean) ?? '') === 1;
            $legitimate = preg_match('/[a-zA-Z]/', $clean) && preg_match('/^[a-zA-Z0-9_\-.]+$/D', $clean);
            if ($numeric && ! $attributable) {
                return $this->fail($result, 2, 'PERSONAL_SMS_REJECTED');
            }
            if (! $numeric && ! $legitimate) {
                return $this->fail($result, 2, 'INVALID_SMS_SENDER');
            }
        } else {
            preg_match('/<([^>]+)>/', $sender, $email);
            $address = strtolower(trim($email[1] ?? $sender));
            $domain = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : $address;
            $legitimate = $domain === 'bank.in' || str_ends_with($domain, '.bank.in')
                || (app()->environment(['local', 'testing']) && ($sender === 'TEST' || str_ends_with($domain, '.paykr.local') || $domain === 'hdfcbank.net'));
            if (! $legitimate && ! $attributable) {
                return $this->fail($result, 2, 'INVALID_EMAIL_DOMAIN');
            }
        }
        $result['stages']['stage2']['status'] = $attributable ? 'FLAGGED_CUSTOMER_SENDER' : 'PASSED';
        if (! preg_match('/(?:credited|credit|cr\b|deposit|deposited|received|transferred)/i', $input->rawText)) {
            return $this->fail($result, 3, 'MISSING_CREDIT_INDICATOR');
        }
        if (! preg_match('/(?:\b(?:rs\.?|inr)\b|₹|(?:rs\.?|inr|₹)\s*\d|\d\s*(?:rs\.?|inr|₹))/i', $input->rawText)) {
            return $this->fail($result, 3, 'MISSING_CURRENCY_INDICATOR');
        }
        $reference = null;
        foreach ([
            '/(?:upi\s*ref(?:erence)?\s*(?:no\.?|num)?|utr(?:\s*no\.?)?|rrn(?:\s*no\.?)?|txn\s*(?:id|ref)?|ref\s*no\.?)[\s:=-]+([0-9]{12})\b/i',
            '/(?:ref(?:\s*no)?\s*[:#\s]?\s*)([0-9]{12})\b/i', '/\b([0-9]{12})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $input->rawText, $match)) {
                $reference = $match[1];
                break;
            }
        }
        if ($reference === null) {
            return $this->fail($result, 3, 'MISSING_12_DIGIT_REFERENCE');
        }
        $amount = AmountExtractor::extract($input->rawText);
        if ($amount === null) {
            return $this->fail($result, 3, 'INVALID_OR_MISSING_AMOUNT');
        }
        $result['amount'] = '₹'.$amount['amountStr'];
        $result['amountPaise'] = (string) $amount['amountPaise'];
        $result['reference'] = $reference;
        $result['stages']['stage3']['status'] = 'PARSED';
        $result['stages']['stage3']['details'] = ['amount' => $result['amount'], 'amountPaise' => $result['amountPaise'], 'reference' => $reference];
        $observerSource = $source === 'GMAIL' ? ObserverSource::GMAIL : ObserverSource::SMS_GATEWAY;
        $normalized = ObservationNormalizer::normalize(new ObservationData($input->receivingAccountId, $observerSource,
            ['text' => $input->rawText, 'targetSessionId' => $session?->id, 'sender' => $sender, 'messageId' => $result['messageId']],
            amountPaise: $amount['amountPaise'], observedAt: $at, bankReferenceNumber: $reference));
        $windowReason = match (true) {
            $session === null => 'NO_ACTIVE_SESSION',
            $at->lessThan(($session->slot_acquired_at ?? $session->created_at)->subSecond()) => 'BEFORE_SESSION_CREATION',
            $session->expires_at !== null && $at->greaterThan($session->expires_at) => 'SESSION_EXPIRED',
            in_array($session->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)
                && ($session->status === PaymentStatus::PROCESSING ? $session->processing_expires_at : $session->expires_at)?->lessThanOrEqualTo(now()) => 'SESSION_EXPIRED',
            default => null,
        };
        if ($windowReason !== null) {
            // Retain parsed bank evidence without asserting who paid or reviving an order.
            // Never retain customer spoofing, invalid account targets or future-dated evidence here.
            if ($account !== null && ! $attributable && $customer?->status !== CustomerStatus::BANNED
                && ($input->paymentSessionId === null || $session !== null) && $at->lessThanOrEqualTo(now())) {
                $held = $this->attribution->process($normalized, $windowReason);
                $result['observationId'] = $held->observationId;

                return $this->fail($result, 4, $windowReason, status: $held->outcomeStatus);
            }

            return $this->fail($result, 4, $windowReason, status: $session === null ? 'UNRESOLVED' : 'REJECTED');
        }
        if ($amount['amountPaise'] !== $session->amount_paise) {
            return $this->fail($result, 4, 'AMOUNT_MISMATCH', 'Payment amount mismatch: session requires '.(new Money($session->amount_paise))->formatINR().', but observation contains '.(new Money($amount['amountPaise']))->formatINR().'.');
        }
        if (PaymentObservation::where('receiving_account_id', $input->receivingAccountId)->where('fingerprint_hash', $normalized->fingerprintHash)->where('source', $observerSource)->exists()) {
            return $this->fail($result, 4, 'DUPLICATE_OBSERVATION');
        }
        if (PaymentObservation::where('receiving_account_id', $input->receivingAccountId)->where('bank_reference_number', $reference)
            ->whereHas('attributedPayment', fn ($query) => $query->where('status', PaymentStatus::SUCCESS)->where('id', '!=', $session->id))->exists()) {
            return $this->fail($result, 4, 'REFERENCE_ALREADY_USED');
        }
        $result['stages']['stage4']['status'] = 'VERIFIED';
        $result['stages']['stage4']['details'] = ['sessionId' => $session->id, 'orderId' => $session->order_id, 'amountMatches' => true, 'referenceValid' => true, 'temporalWindowValid' => true];
        if ($customer?->status === CustomerStatus::BANNED) {
            return $this->fail($result, 5, 'BANNED_IDENTITY');
        }
        if ($customer !== null && $session->customer_id === $customer->id && $attributable
            && in_array($session->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true)) {
            // Stage 3 established the credit claim; attributable senders are never bank-originated.
            $suppressed = $input->suppressBan && $trustedTest;
            $this->fraud->record($input, $customer, $session, $sender, $source, $result['stages']['stage3'], $result['stages']['stage4'], $suppressed);
            if ($suppressed) {
                $result['stages']['stage5']['status'] = 'SUSPICIOUS_FLAGGED';
                $result['stages']['stage5']['isSuspicious'] = true;
                $result['stages']['stage5']['threatType'] = 'FRAUDULENT_PAYMENT_INJECTION';
                $result['stages']['stage5']['autoBanApplied'] = false;
                $result['stages']['stage5']['banSuppressed'] = true;
                $result['stages']['stage6']['outcomeStatus'] = 'SUSPICIOUS';
                $result['stages']['stage6']['status'] = 'FLAGGED_SUSPICIOUS';
                $result['status'] = 'SUSPICIOUS';
                $result['reason'] = $result['failingCondition'] = 'FRAUDULENT_PAYMENT_INJECTION';
                $result['failingStage'] = 5;

                return new VerificationResult($result);
            }

            return $this->fail($result, 5, 'FRAUDULENT_PAYMENT_INJECTION');
        }
        if ($attributable) {
            // A customer-owned sender is never bank evidence, even without a ban-eligible live session.
            return $this->fail($result, 5, 'CUSTOMER_SENDER_NOT_BANK');
        }
        $result['stages']['stage5']['status'] = 'PASSED';
        $result['stages']['stage5']['isSuspicious'] = false;
        $result['stages']['stage5']['autoBanApplied'] = false;
        $attribution = $this->attribution->process($normalized);
        PaymentObservation::whereKey($attribution->observationId)->update(['verified_at' => now()]);
        $result['success'] = $attribution->attributed;
        $result['status'] = $attribution->attributed ? 'VERIFIED' : ($attribution->outcomeStatus === 'NEEDS_REVIEW' ? 'NEEDS_REVIEW' : 'UNRESOLVED');
        if ($attribution->outcomeStatus === 'NEEDS_REVIEW') {
            $result['reason'] = 'AMBIGUOUS_LATE_CREDIT';
            $result['observationId'] = $attribution->observationId;
        }
        $result['stages']['stage6']['status'] = $attribution->attributed ? 'ATTRIBUTED' : 'UNRESOLVED';
        $result['stages']['stage6']['outcomeStatus'] = $attribution->outcomeStatus;
        $result['stages']['stage6']['details'] = [
            'observationId' => $attribution->observationId,
            'attributedSessionId' => $attribution->paymentSessionId,
            'verificationLevel' => $attribution->verificationLevel,
            'verificationTag' => $attribution->verificationTag,
        ];
        $result['session']['status'] = $session->refresh()->status->value;

        return new VerificationResult($result);
    }

    /** @param array<string, string> $identity */
    private function isCustomerSender(Customer $customer, string $sender, array $identity): bool
    {
        $mobile = substr(preg_replace('/\D/', '', $customer->mobile_number) ?? '', -10);
        $senderPhone = substr(preg_replace('/\D/', '', $sender) ?? '', -10);
        $email = strtolower(trim($customer->email ?? ''));

        return ($mobile !== '' && strlen($mobile) === 10 && $senderPhone === $mobile)
            || ($email !== '' && str_contains(strtolower($sender), $email))
            || ($identity['customerId'] ?? null) === $customer->id
            || ($mobile !== '' && substr(preg_replace('/\D/', '', $identity['phone'] ?? '') ?? '', -10) === $mobile)
            || ($email !== '' && strtolower(trim($identity['email'] ?? '')) === $email);
    }

    /** @param array<string, mixed> $result */
    private function fail(array $result, int $stage, string $reason, ?string $details = null, string $status = 'REJECTED'): VerificationResult
    {
        $result['status'] = $status;
        $result['failingStage'] = $stage;
        $result['failingCondition'] = $result['reason'] = $reason;
        $result['details'] = $details ?? $reason;
        $result['stages']['stage'.$stage]['status'] = 'FAILED';
        $result['stages']['stage'.$stage]['reason'] = $reason;
        $result['stages']['stage'.$stage]['details'] = $details ?? $reason;

        return new VerificationResult($result);
    }
}
