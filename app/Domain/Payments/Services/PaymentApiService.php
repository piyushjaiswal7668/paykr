<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Customers\Services\CustomerAuthService;
use App\Domain\Merchants\Enums\SellingMode;
use App\Domain\Payments\Data\PaymentApiData;
use App\Domain\Payments\Data\PaymentApiResult;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\MobileNumber;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;

final class PaymentApiService
{
    public function __construct(private PaymentTimingService $timing, private PaymentTransitionService $transitions, private CustomerAuthService $customers) {}

    public function execute(PaymentApiData $data): PaymentApiResult
    {
        if ($data->operation === 'create') {
            return $this->create($data);
        }
        $payment = ($data->operation === 'order-cod' ? PaymentSession::where('order_id', $data->orderId) : PaymentSession::whereKey($data->id))->first() ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
        if ($data->operation === 'booking-status') {
            return new PaymentApiResult(app(BookingService::class)->update($payment->id, $data->details));
        }
        if ($data->operation === 'notification-preferences') {
            return new PaymentApiResult(app(BookingService::class)->consent($payment->id, (bool) $data->details['enabled'], 'MERCHANT_API_KEY'));
        }
        if (in_array($data->operation, ['recheck', 'request-review'], true)) {
            return new PaymentApiResult(app(PaymentRecheckService::class)->execute($payment->id, $data->operation === 'request-review', $data->details));
        }
        $payment = $this->timing->expireIfDue($payment->id);
        $result = $this->summary($payment);
        if (in_array($data->operation, ['cod', 'order-cod'], true)) {
            $result['isCod'] = $payment->method === PaymentMethod::COD;
            $result['placedAt'] = $payment->completed_at;
            $result['customer'] = $payment->customer === null ? null : array_intersect_key($this->customers->summary($payment->customer), array_flip(['id', 'name', 'email', 'mobileNumber']));
        }

        return new PaymentApiResult($result);
    }

    private function create(PaymentApiData $data): PaymentApiResult
    {
        if ($data->whatsappUpdatesConsent && ! $data->identity->secret) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        $amount = Money::fromRupees($data->amount)->paise;

        return DB::transaction(function () use ($data, $amount): PaymentApiResult {
            // Serialize order/idempotency decisions within this merchant.
            Merchant::whereKey($data->identity->merchantId)->lockForUpdate()->firstOrFail();
            PaymentReceivingAccount::orderBy('id')->lockForUpdate()->get();
            $requestHash = hash('sha256', json_encode([$data->orderId, $amount, $data->method->value, $data->customerId, $data->customer, $data->deviceId, $data->returnUrl, $data->metadata, ...($data->whatsappUpdatesConsent ? [true] : [])], JSON_THROW_ON_ERROR));
            $device = $data->deviceId === null ? null : Device::byPublicId($data->deviceId)->first();
            $existing = $data->idempotencyKey === null ? null : PaymentSession::where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing !== null) {
                if (! $existing->status->isTerminal()) {
                    $this->assertSameDevice($existing, $device?->id);
                }
                if (($existing->metadata['idempotencyRequestHash'] ?? null) !== $requestHash) {
                    throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
                }

                return new PaymentApiResult($this->summary($existing));
            }
            $existing = PaymentSession::where('order_id', $data->orderId)->first();
            $order = $data->orderId;
            if ($existing !== null) {
                $existing = $this->timing->expireIfDue($existing->id);
                if ($existing->status === PaymentStatus::SUCCESS) {
                    return new PaymentApiResult($this->summary($existing));
                }
                if (! $existing->status->isTerminal()) {
                    $this->assertSameDevice($existing, $device?->id);

                    return new PaymentApiResult($this->summary($existing) + ['activeSessionDetected' => true]);
                }
                do {
                    $order = preg_replace('/-R[0-9]{4}$/', '', $data->orderId).'-R'.random_int(1000, 9999);
                } while (PaymentSession::where('order_id', $order)->exists());
            }
            $settings = MerchantPaymentSettings::first();
            $enabled = match ($data->method) {
                PaymentMethod::UPI => true,
                PaymentMethod::COD => $settings?->selling_mode === SellingMode::ONLINE && $settings->cod_enabled,
                PaymentMethod::CASH_COUNTER => $settings?->selling_mode === SellingMode::OFFLINE && $settings->cash_enabled,
                PaymentMethod::CARD_COUNTER => $settings?->selling_mode === SellingMode::OFFLINE && $settings->card_enabled,
            };
            if (! $enabled) {
                throw new ValidationException(ErrorCode::PAYMENT_METHOD_DISABLED);
            }
            $customerId = $data->customerId;
            if ($customerId === null && $data->customer !== []) {
                $mobile = $data->customer['mobileNumber'] ?? $data->customer['phoneNumber'] ?? null;
                $mobile = $mobile === null ? null : (new MobileNumber($mobile))->value;
                $email = strtolower($data->customer['email'] ?? '');
                $customer = $mobile === null ? null : Customer::where('mobile_number', $mobile)->first();
                $customer ??= $email === '' ? null : Customer::where('email', $email)->first();
                if ($customer === null && $mobile !== null) {
                    $customer = Customer::create(['name' => $data->customer['name'] ?? 'Customer '.substr($mobile, -4), 'email' => $email ?: $mobile.'@customer.paykr.local', 'mobile_number' => $mobile, 'status' => 'ACTIVE']);
                }
                $customerId = $customer?->id;
            }
            if ($customerId !== null) {
                $customer = Customer::whereKey($customerId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::NOT_FOUND);
                $this->customers->assertAllowed($customer);
                if (MerchantCustomerBlock::where('customer_id', $customer->id)->exists()) {
                    throw new ValidationException(ErrorCode::CUSTOMER_BLOCKED_BY_MERCHANT);
                }
                foreach (PaymentSession::where('customer_id', $customer->id)->whereIn('status', ['ACTIVE', 'PROCESSING', 'QUEUED'])->get() as $live) {
                    $live = $this->timing->expireIfDue($live->id);
                    if (! $live->status->isTerminal()) {
                        $this->assertSameDevice($live, $device?->id);
                    }
                }
            }
            $account = $data->method === PaymentMethod::UPI ? PaymentReceivingAccount::where('is_active', true)->first() : null;
            if ($data->method === PaymentMethod::UPI && $account === null) {
                throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
            }
            $issuedToken = null;
            if ($data->deviceId !== null && $device === null) {
                $issuedToken = bin2hex(random_bytes(32));
                $device = Device::firstOrCreate(['legacy_id' => $data->deviceId], ['device_token_hash' => hash('sha256', $issuedToken), 'public_key' => 'adhoc_'.$data->deviceId, 'fingerprint_hash' => hash('sha256', $data->deviceId), 'status' => 'ACTIVE', 'last_seen_at' => now()]);
                if (! $device->wasRecentlyCreated) {
                    $issuedToken = null;
                }
            }
            $metadata = $this->publicMetadata($data->metadata);
            if ($data->idempotencyKey !== null) {
                $metadata['idempotencyRequestHash'] = $requestHash;
            }
            if ($account !== null) {
                $metadata['receivingAccountSnapshot'] = ['id' => $account->id, 'upiId' => $account->upi_id, 'payeeName' => $account->payee_name, 'isMerchantEnabled' => $account->is_merchant_enabled];
            }
            $payment = PaymentSession::create(['merchant_id' => $data->identity->merchantId, 'order_id' => $order, 'amount_paise' => $amount, 'currency' => 'INR', 'method' => $data->method, 'status' => PaymentStatus::INITIATED, 'customer_id' => $customerId, 'device_id' => $device?->id, 'receiving_account_id' => $account?->id, 'return_url' => $data->returnUrl, 'idempotency_key' => $data->idempotencyKey, 'is_test' => $data->identity->isTest, 'metadata' => $metadata]);
            if ($data->whatsappUpdatesConsent) {
                app(BookingService::class)->consent($payment->id, true, 'MERCHANT_API_KEY');
                $payment->refresh();
            }
            if ($data->method !== PaymentMethod::UPI) {
                $payment = $this->transitions->transition($payment->id, PaymentStatus::ACTIVE, new Actor(ActorType::MERCHANT_API_KEY, merchantId: $data->identity->merchantId));
            }

            return new PaymentApiResult($this->summary($payment) + ($issuedToken === null ? [] : ['deviceToken' => $issuedToken]), true);
        }, 5);
    }

    public function assertSameDevice(PaymentSession $payment, ?string $deviceId): void
    {
        if ($payment->device_id !== null && $payment->device_id !== $deviceId) {
            throw new ValidationException(ErrorCode::DEVICE_SESSION_MISMATCH, legacy: ['error' => 'DUPLICATE_SESSION_DETECTED', 'action' => 'END_SESSION_AND_CREATE_NEW', 'session_id' => $payment->id, 'conflicting_session_id' => $payment->id]);
        }
    }

    /** @return array<string, mixed> */
    public function summary(PaymentSession $payment): array
    {
        return ['bookingStatus' => $payment->booking_status, 'bookingVersion' => $payment->booking_version, 'paymentId' => $payment->id, 'orderId' => $payment->order_id, 'amount' => $payment->amount_paise / 100, 'amountPaise' => $payment->amount_paise, 'currency' => $payment->currency, 'status' => $payment->status->value, 'internalStatus' => $payment->status->value, 'method' => $payment->method->value, 'verificationLevel' => $payment->verification_level, 'verificationSources' => $payment->verification_sources ?? [], 'verificationTag' => $payment->verification_tag, 'utr' => $payment->attributedObservation?->bank_reference_number, 'completedAt' => $payment->completed_at, 'returnUrl' => $payment->return_url, 'metadata' => $this->publicMetadata($payment->metadata ?? []), 'queuePosition' => $payment->queue_position, 'checkoutUrl' => url('/checkout/'.$payment->id)];
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function publicMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (! preg_match('/gmail|test.?mode|token|secret|password|receivingAccountSnapshot|idempotencyRequestHash/i', (string) $key)) {
                $clean[$key] = is_array($value) ? $this->publicMetadata($value) : $value;
            }
        }

        return $clean;
    }
}
