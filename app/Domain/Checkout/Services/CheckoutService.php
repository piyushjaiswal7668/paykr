<?php

declare(strict_types=1);

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Data\CheckoutData;
use App\Domain\Checkout\Data\CheckoutResult;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\CustomerAuthService;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Services\DeviceAccessService;
use App\Domain\Observers\Services\UpiHealthService;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentActionTriggered;
use App\Domain\Payments\Services\BookingService;
use App\Domain\Payments\Services\PaymentApiService;
use App\Domain\Payments\Services\PaymentMethodService;
use App\Domain\Payments\Services\PaymentRecheckService;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\QrCodeService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Payments\Services\UpiProviderRegistry;
use App\Domain\Payments\Services\UpiUri;
use App\Domain\Realtime\Services\ChannelAuthorizer;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantTestMode;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CheckoutService
{
    public function __construct(private TenantContext $tenants, private DeviceAccessService $devices, private CustomerAuthService $customers, private PaymentApiService $payments, private PaymentTimingService $timing, private PaymentTransitionService $transitions, private PaymentMethodService $methods, private SlotQueueService $queue, private PublicBranding $branding) {}

    public function execute(CheckoutData $data): CheckoutResult
    {
        if ($data->operation === 'server-health') {
            $merchant = Merchant::find($data->paymentId) ?? throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);

            return $this->tenants->run($merchant->id, fn () => new CheckoutResult(app(UpiHealthService::class)->check()));
        }
        $merchantId = $this->tenants->runUnscoped(fn () => PaymentSession::whereKey($data->paymentId)->value('merchant_id')) ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);

        return $this->tenants->run($merchantId, function () use ($data): CheckoutResult {
            if (! in_array($data->operation, ['state', 'cod', 'stream', 'events', 'qr', 'simulate-observation'], true) && ($data->deviceId === null || $data->deviceId === '')) {
                throw new ValidationException(ErrorCode::DEVICE_REQUIRED);
            }
            if (in_array($data->operation, ['end-and-restart-payment', 'end-session-and-create-new'], true)) {
                return $this->restart($data);
            }
            if ($data->operation === 'simulate-observation') {
                return $this->simulate($data);
            }
            $customerRead = $data->operation === 'state' && app(ChannelAuthorizer::class)->customerOwns(PaymentSession::findOrFail($data->paymentId), $data->customerToken);
            $access = $customerRead ? [] : $this->devices->check($data->paymentId, $data->deviceId, $data->deviceToken);
            $payment = $this->timing->expireIfDue($data->paymentId);
            $actor = new Actor(ActorType::DEVICE, $payment->device_id, $payment->merchant_id);
            if ($data->operation === 'state' && $payment->customer !== null && ($payment->customer->status === CustomerStatus::BANNED || MerchantCustomerBlock::where('customer_id', $payment->customer_id)->exists())) {
                return new CheckoutResult(['status' => 'BLOCKED', 'isBanned' => true, 'fraudDetected' => true, 'customerName' => $payment->customer->name, 'blockedNotice' => ['title' => 'FRAUDULENT ACTIVITY DETECTED', 'subtitle' => 'Payment Session Terminated & Account Suspended', 'message' => 'Your account has been temporarily blocked because suspicious payment activity was detected. Our team will review the activity before access can be restored.', 'investigating' => true, 'reason' => 'Suspicious payment activity']]);
            }
            if ($payment->customer !== null) {
                $this->customers->assertAllowed($payment->customer);
            }
            if ($payment->customer_id !== null && MerchantCustomerBlock::where('customer_id', $payment->customer_id)->exists()) {
                throw new ValidationException(ErrorCode::CUSTOMER_BLOCKED_BY_MERCHANT);
            }
            if (in_array($data->operation, ['stream', 'events'], true)) {
                return new CheckoutResult(['message' => 'Subscribe to the authenticated Reverb payment channel.', 'channel' => 'payment.'.$payment->id], gone: true);
            }
            if (in_array($data->operation, ['recheck', 'request-review', 'notification-preferences'], true)) {
                if (! app(ChannelAuthorizer::class)->customerOwns($payment, $data->customerToken)) {
                    throw new ValidationException(ErrorCode::UNAUTHENTICATED);
                }

                if ($data->operation === 'notification-preferences') {
                    return new CheckoutResult(app(BookingService::class)->consent($payment->id, (bool) $data->details['enabled'], 'CUSTOMER'));
                }

                return new CheckoutResult(app(PaymentRecheckService::class)->execute($payment->id, $data->operation === 'request-review', $data->details));
            }
            if ($data->operation === 'bind-customer') {
                $payment = $this->bind($payment, $data, $actor);
            } elseif ($data->operation === 'cancel') {
                if (! $payment->status->isTerminal()) {
                    $payment = $this->transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
                    $this->audit($payment, 'PAYMENT_CANCELLED_BY_USER', $actor);
                }
            } elseif ($data->operation === 'payment-done') {
                if (! in_array($payment->status, [PaymentStatus::ACTIVE, PaymentStatus::PROCESSING], true) || $payment->method !== PaymentMethod::UPI) {
                    throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
                }
                if ($payment->status === PaymentStatus::ACTIVE) {
                    $payment = $this->timing->paymentDone($payment->id, $actor);
                    $this->audit($payment, 'CUSTOMER_CLICKED_PAYMENT_DONE', $actor);
                }
            } elseif ($data->operation === 'select-method') {
                $payment = $this->methods->select($payment->id, $data->method, $actor);
            } elseif ($data->operation === 'confirm-cod') {
                $payment = DB::transaction(function () use ($payment, $actor): PaymentSession {
                    $payment = $this->methods->select($payment->id, PaymentMethod::COD, $actor);
                    $payment->update(['verification_level' => 1, 'verification_tag' => 'COD_ORDER_PLACED']);
                    $payment = $this->transitions->transition($payment->id, PaymentStatus::SUCCESS, $actor);
                    $this->audit($payment, 'COD_ORDER_PLACED', $actor);

                    return $payment;
                }, 5);
            } elseif ($data->operation === 'trigger-hook') {
                if ($payment->status->isTerminal()) {
                    throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
                }
                $this->audit($payment, 'HOOK_'.$data->action, $actor, $data->details);
                event(new PaymentActionTriggered($payment->merchant_id, $payment->id, $data->action));
            } elseif ($data->operation === 'retry-new-payment') {
                $payment = $this->retry($payment, $data, $actor);
            }
            $state = $this->state($payment);
            if ($data->operation === 'qr') {
                if ($state['upiUri'] === null) {
                    throw new ValidationException(ErrorCode::NOT_FOUND);
                }

                return new CheckoutResult([], svg: (new QrCodeService)->svg($state['upiUri']));
            }
            if ($data->operation === 'cod') {
                $state['isCod'] = $payment->method === PaymentMethod::COD;
                $state['placedAt'] = $payment->completed_at;
            }
            if (isset($access['deviceToken'])) {
                $state['deviceToken'] = $access['deviceToken'];
            }

            return new CheckoutResult($state);
        });
    }

    private function restart(CheckoutData $data): CheckoutResult
    {
        $customer = $this->customers->customerForToken($data->customerToken);
        $device = Device::byPublicId($data->deviceId ?? '')->first();
        if ($device === null || ! $this->devices->tokenValid($device, $data->deviceToken) || $device->status !== DeviceStatus::ACTIVE) {
            throw new ValidationException(ErrorCode::DEVICE_NOT_AUTHORIZED);
        }

        return DB::transaction(function () use ($data, $customer, $device): CheckoutResult {
            Merchant::whereKey($this->tenants->merchantId())->lockForUpdate()->firstOrFail();
            $this->lockAccounts();
            $payment = PaymentSession::whereKey($data->paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->customer_id !== $customer->id) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            if ($payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            $actor = new Actor(ActorType::CUSTOMER, $customer->id, $payment->merchant_id);
            $terminated = [];
            $others = PaymentSession::where('customer_id', $customer->id)->whereIn('status', ['INITIATED', 'ACTIVE', 'PROCESSING', 'QUEUED'])->orderBy('id')->get();
            foreach ($others as $other) {
                if ($other->id !== $payment->id && $other->device_id === $device->id) {
                    continue;
                }
                $other->update(['metadata' => array_merge($other->metadata ?? [], ['reason' => 'REPLACED_ON_ANOTHER_DEVICE', 'replacedByDeviceId' => $device->publicId()])]);
                $this->transitions->transition($other->id, PaymentStatus::CANCELLED, $actor);
                $terminated[] = $other->id;
            }
            do {
                $order = preg_replace('/-R[0-9]{4}$/', '', $payment->order_id).'-R'.random_int(1000, 9999);
            } while (PaymentSession::where('order_id', $order)->exists());
            $new = PaymentSession::create(['merchant_id' => $payment->merchant_id, 'customer_id' => $customer->id, 'device_id' => $device->id, 'order_id' => $order, 'amount_paise' => $payment->amount_paise, 'currency' => $payment->currency, 'method' => $payment->method, 'status' => PaymentStatus::INITIATED, 'return_url' => $payment->return_url, 'is_test' => $payment->is_test, 'metadata' => array_merge($this->payments->publicMetadata($payment->metadata ?? []), ['reboundFromSessionId' => $payment->id])]);
            $new = $this->activate($new, $actor);
            $this->audit($new, 'PAYMENT_SESSION_RECREATED_ON_NEW_DEVICE', $actor);

            return new CheckoutResult(['newSessionId' => $new->id, 'checkoutUrl' => url('/checkout/'.$new->id), 'terminatedSessionIds' => $terminated], created: true);
        }, 5);
    }

    private function simulate(CheckoutData $data): CheckoutResult
    {
        $payment = PaymentSession::findOrFail($data->paymentId);
        $user = $data->userId === null ? null : User::find($data->userId);
        if ($user === null || (! $user->is_platform_admin && ! $user->merchants()->whereKey($payment->merchant_id)->wherePivotIn('role', ['owner', 'developer'])->exists())) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        if (! $payment->is_test || ! MerchantTestMode::where('enabled_at', '<=', now()->format('Y-m-d H:i:s.v'))->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->exists()) {
            throw new ValidationException(ErrorCode::TEST_MODE_INACTIVE);
        }
        if ($payment->status->isTerminal() || $payment->receiving_account_id === null) {
            throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
        }
        $result = app(VerificationPipeline::class)->process(new VerificationData(source: $data->details['source'] ?? 'SMS', rawText: $data->details['rawText'] ?? '', receivingAccountId: $payment->receiving_account_id, sender: $data->details['sender'] ?? null, paymentSessionId: $payment->id, isTest: true, testModeActive: true, isSimulated: true));

        return new CheckoutResult(['simulation' => $result->data]);
    }

    private function bind(PaymentSession $payment, CheckoutData $data, Actor $actor): PaymentSession
    {
        $customer = $this->customers->customerForToken($data->customerToken);

        return DB::transaction(function () use ($payment, $customer, $actor): PaymentSession {
            $this->lockAccounts();
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->customers->assertAllowed($customer);
            $payment = PaymentSession::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            $previousCustomerId = $payment->customer_id;
            if ($previousCustomerId !== null && $previousCustomerId !== $customer->id) {
                // API customer details are provisional until the first authenticated binding.
                // A conflict can leave an authenticated payment INITIATED, so status alone is insufficient.
                $alreadyAuthenticated = AuditLog::where('entity_type', 'PAYMENT_SESSION')
                    ->where('entity_id', $payment->id)->where('action', 'CUSTOMER_AUTHENTICATED_AND_BOUND')->exists();
                if ($payment->status !== PaymentStatus::INITIATED || $alreadyAuthenticated) {
                    throw new ValidationException(ErrorCode::SESSION_ALREADY_BOUND);
                }
            }
            if (MerchantCustomerBlock::where('customer_id', $customer->id)->exists()) {
                throw new ValidationException(ErrorCode::CUSTOMER_BLOCKED_BY_MERCHANT);
            }
            $conflicts = PaymentSession::where('customer_id', $customer->id)->whereKeyNot($payment->id)->whereIn('status', ['ACTIVE', 'PROCESSING', 'QUEUED'])->get();
            foreach ($conflicts as $conflict) {
                $this->payments->assertSameDevice($conflict, $payment->device_id);
            }
            $payment->update(['customer_id' => $customer->id]);
            if ($payment->device !== null) {
                $payment->device->update(['customer_id' => $customer->id]);
            }
            if ($conflicts->isEmpty() && $payment->status === PaymentStatus::INITIATED) {
                $payment = $this->activate($payment, $actor);
            }
            $this->audit($payment, 'CUSTOMER_AUTHENTICATED_AND_BOUND', new Actor(ActorType::CUSTOMER, $customer->id, $payment->merchant_id), ['previousCustomerId' => $previousCustomerId]);

            return $payment;
        }, 5);
    }

    private function retry(PaymentSession $payment, CheckoutData $data, Actor $actor): PaymentSession
    {
        $customer = $this->customers->customerForToken($data->customerToken);
        if ($payment->customer_id !== $customer->id) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return DB::transaction(function () use ($payment, $actor): PaymentSession {
            $this->lockAccounts();
            $payment = PaymentSession::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
            }
            $others = PaymentSession::whereKeyNot($payment->id)->whereIn('status', ['ACTIVE', 'PROCESSING', 'QUEUED'])->where(fn ($q) => $q->where('customer_id', $payment->customer_id)->orWhere(fn ($device) => $device->whereNull('customer_id')->where('device_id', $payment->device_id)))->get();
            foreach ($others as $other) {
                $other->update(['metadata' => array_merge($other->metadata ?? [], ['reason' => 'USER_RETRY_NEW_PAYMENT', 'replacedBySessionId' => $payment->id])]);
                $this->transitions->transition($other->id, PaymentStatus::CANCELLED, $actor);
            }

            return $payment->status === PaymentStatus::INITIATED ? $this->activate($payment, $actor) : $payment;
        }, 5);
    }

    private function activate(PaymentSession $payment, Actor $actor): PaymentSession
    {
        if ($payment->method !== PaymentMethod::UPI) {
            return $this->transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
        }
        $account = PaymentReceivingAccount::where('is_active', true)->first() ?? throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);

        $payment->update(['receiving_account_id' => $account->id, 'metadata' => array_merge($payment->metadata ?? [], ['receivingAccountSnapshot' => ['id' => $account->id, 'upiId' => $account->upi_id, 'payeeName' => $account->payee_name, 'isMerchantEnabled' => $account->is_merchant_enabled]])]);

        return $this->queue->enqueueOrActivate($payment->id, $account->id, $actor);
    }

    private function lockAccounts(): void
    {
        PaymentReceivingAccount::orderBy('id')->lockForUpdate()->get();
    }

    /** @param array<string, mixed> $details */
    private function audit(PaymentSession $payment, string $action, Actor $actor, array $details = []): void
    {
        AuditLog::create(['merchant_id' => $payment->merchant_id, 'entity_type' => 'PAYMENT_SESSION', 'entity_id' => $payment->id, 'action' => $action, 'actor_type' => $actor->type->value, 'actor_id' => $actor->id, 'details' => $details]);
    }

    /** @return array<string, mixed> */
    private function state(PaymentSession $payment): array
    {
        $branding = $this->branding->get();
        if ($payment->status->isTerminal()) {
            return array_intersect_key($this->payments->summary($payment), array_flip(['bookingStatus', 'bookingVersion', 'paymentId', 'orderId', 'amount', 'amountPaise', 'currency', 'status', 'method', 'utr', 'verificationTag', 'completedAt', 'returnUrl', 'metadata'])) + ['id' => $payment->id, 'merchantName' => Merchant::findOrFail($payment->merchant_id)->business_name, 'timeRemainingSeconds' => 0, 'isTerminal' => true, 'branding' => $branding, 'upiUri' => null];
        }
        $account = $payment->receivingAccount;
        $deadline = $payment->status === PaymentStatus::PROCESSING ? $payment->processing_expires_at : $payment->expires_at;
        $conflict = $payment->customer_id === null ? null : PaymentSession::where('customer_id', $payment->customer_id)->whereKeyNot($payment->id)->whereIn('status', ['ACTIVE', 'PROCESSING', 'QUEUED'])->first();
        if ($conflict !== null) {
            $this->payments->assertSameDevice($conflict, $payment->device_id);
        }

        return $this->payments->summary($payment) + ['id' => $payment->id, 'merchantName' => Merchant::findOrFail($payment->merchant_id)->business_name, 'branding' => $branding, 'sellingMode' => $branding['sellingMode'], 'enabledMethods' => $branding['enabledPaymentMethods'], 'customer' => $payment->customer === null ? null : array_intersect_key($this->customers->summary($payment->customer), array_flip(['id', 'name', 'email', 'mobileNumber'])), 'hasActiveSessionConflict' => $conflict !== null, 'conflictingSession' => $conflict === null ? null : array_intersect_key($this->payments->summary($conflict), array_flip(['paymentId', 'orderId', 'amount', 'status', 'checkoutUrl'])), 'isTerminal' => $payment->status->isTerminal(), 'timeRemainingSeconds' => $deadline === null ? 0 : max(0, (int) ceil(now()->diffInSeconds($deadline, false))), 'expiresAt' => $deadline, 'serverNow' => now()->toISOString(), 'upiUri' => $payment->status === PaymentStatus::ACTIVE && $payment->method === PaymentMethod::UPI && $account !== null ? UpiUri::build($account->upi_id, $account->payee_name, new Money($payment->amount_paise), $payment->order_id) : null, 'payeeVpa' => $account?->upi_id, 'payeeName' => $account?->payee_name, 'isMerchantEnabled' => $account === null ? false : $account->is_merchant_enabled, 'upiMode' => $account?->is_merchant_enabled ? 'MERCHANT' : 'NON_MERCHANT', 'upiServerIndicator' => array_intersect_key(app(UpiHealthService::class)->check(), array_flip(['status', 'tier', 'label', 'isWorking', 'warning', 'recommendedAlternative'])), 'receivingAccountId' => $account?->id, 'providers' => (new UpiProviderRegistry)->all(), 'canClickPaymentDone' => $payment->status === PaymentStatus::ACTIVE && $payment->method === PaymentMethod::UPI, 'supportEmails' => $branding['supportEmails'], 'supportMobiles' => $branding['supportMobiles'], 'privacyPolicyUrl' => $branding['privacyPolicyUrl'], 'deviceId' => $payment->device?->publicId(), 'counterInstructions' => $payment->method === PaymentMethod::CARD_COUNTER ? $branding['cardCounterInstructions'] : $branding['counterInstructions']];
    }
}
