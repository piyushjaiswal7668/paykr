<?php

declare(strict_types=1);
use App\Domain\Merchants\Data\ApiIdentity;
use App\Domain\Merchants\Enums\ApiKeyMode;
use App\Domain\Merchants\Enums\ApiKeyType;
use App\Domain\Merchants\Services\ApiKeyAuthenticator;
use App\Domain\Merchants\Services\ApiKeyService;
use App\Domain\Payments\Data\PaymentApiData;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentApiService;
use App\Domain\Payments\Services\PaymentMethodService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);
beforeEach(function () {
    Cache::flush();
    Bus::fake();
    $this->merchant = Merchant::factory()->create(['allowed_origins' => ['https://shop.example.com']]);
    $this->pk = 'pk_live_'.str_repeat('a', 64);
    $this->sk = 'sk_live_'.str_repeat('b', 64);
    app(TenantContext::class)->run($this->merchant->id, function () {
        PaymentReceivingAccount::factory()->create(['merchant_id' => $this->merchant->id, 'is_active' => true]);
        foreach ([$this->pk => 'publishable', $this->sk => 'secret'] as $raw => $type) {
            MerchantApiKey::factory()->create(['merchant_id' => $this->merchant->id, 'key_hash' => hash('sha256', $raw), 'type' => $type, 'mode' => 'live', 'revoked_at' => null]);
        }
    });
});
it('requires explicit keys and origins and isolates secret-key reads', function () {
    $body = ['orderId' => 'ORDER-1', 'amount' => '10.25'];
    $this->postJson('/api/v1/payments', $body)->assertUnauthorized();
    $this->withHeader('X-Merchant-Id', $this->merchant->id)->postJson('/api/v1/payments', $body)->assertUnauthorized();
    $this->withHeader('X-Api-Key', $this->pk)->postJson('/api/v1/payments', $body)->assertForbidden();
    $payment = $this->withHeader('Origin', 'https://shop.example.com')->postJson('/api/v1/payments', $body)->assertCreated()->assertJsonPath('amountPaise', 1025)->assertJsonPath('status', 'INITIATED')->json('paymentId');
    $this->getJson('/api/v1/payments/'.$payment)->assertUnauthorized();
    $this->withHeader('X-Api-Key', $this->sk)->getJson('/api/v1/payments/'.$payment)->assertOk();
    $other = Merchant::factory()->create();
    app(TenantContext::class)->run($other->id, fn () => PaymentSession::factory()->create(['merchant_id' => $other->id, 'id' => '11111111-1111-4111-8111-111111111111']));
    $this->getJson('/api/v1/payments/11111111-1111-4111-8111-111111111111')->assertNotFound();
    expect(app(TenantContext::class)->merchantId())->toBeNull();
});
it('deduplicates requests and prevents another device from continuing a live order', function () {
    $body = ['orderId' => 'ORDER-1', 'amount' => '12', 'deviceId' => 'legacy-browser'];
    $response = $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', $body)->assertCreated();
    expect($response->json('deviceToken'))->toBeString();
    $this->postJson('/api/v1/payments', $body)->assertOk()->assertJsonPath('paymentId', $response->json('paymentId'));
    $this->postJson('/api/v1/payments', array_replace($body, ['deviceId' => 'another']))->assertStatus(409)->assertJsonPath('error', 'DUPLICATE_SESSION_DETECTED');
    $new = ['orderId' => 'ORDER-2', 'amount' => '15', 'metadata' => ['reference' => 'safe', 'gmailToken' => 'hidden']];
    $result = $this->withHeader('Idempotency-Key', 'unique-key')->postJson('/api/v1/payments', $new)->assertCreated()->assertJsonPath('metadata', ['reference' => 'safe']);
    $this->postJson('/api/v1/payments', $new)->assertOk()->assertJsonPath('paymentId', $result->json('paymentId'));
    $this->postJson('/api/v1/payments', array_replace($new, ['amount' => '99']))->assertStatus(409);
});

it('issues hashed live and test keys only to authorized merchant staff', function () {
    $owner = User::factory()->create();
    $this->merchant->users()->attach($owner, ['role' => 'owner']);
    $issued = app(ApiKeyService::class)->issue($this->merchant->id, $owner->id, ApiKeyType::SECRET, ApiKeyMode::TEST);
    expect($issued->key)->toStartWith('sk_test_');
    app(TenantContext::class)->run($this->merchant->id, function () use ($issued) {
        expect(MerchantApiKey::find($issued->id)->key_hash)->toBe(hash('sha256', $issued->key));
    });
    $payment = $this->withHeader('X-Api-Key', $issued->key)->postJson('/api/v1/payments', ['orderId' => 'TEST-ORDER', 'amount' => '10'])->assertCreated()->json('paymentId');
    app(TenantContext::class)->run($this->merchant->id, function () use ($issued, $payment) {
        expect(PaymentSession::find($payment)->is_test)->toBeTrue();
        MerchantApiKey::find($issued->id)->update(['revoked_at' => now()]);
    });
    $this->getJson('/api/v1/payments/'.$payment)->assertUnauthorized();
    $stranger = User::factory()->create();
    expect(fn () => app(ApiKeyService::class)->issue($this->merchant->id, $stranger->id, ApiKeyType::PUBLISHABLE, ApiKeyMode::LIVE))->toThrow(ValidationException::class);
});

it('resolves customer hints, rejects bans and creates a new order after closure', function () {
    $body = ['orderId' => 'HINT-ORDER', 'amount' => 15, 'customer' => ['name' => 'Hint Customer', 'mobileNumber' => '9876543210', 'email' => 'hint@example.com']];
    $id = $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', $body)->assertCreated()->json('paymentId');
    app(TenantContext::class)->run($this->merchant->id, function () use ($id) {
        $payment = PaymentSession::find($id);
        expect($payment->customer->email)->toBe('hint@example.com');
        app(PaymentTransitionService::class)->transition($id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
    });
    $retry = $this->postJson('/api/v1/payments', $body)->assertCreated();
    expect($retry->json('orderId'))->toMatch('/^HINT-ORDER-R[0-9]{4}$/');
    Customer::where('mobile_number', '9876543210')->update(['status' => 'BANNED']);
    $this->postJson('/api/v1/payments', array_replace($body, ['orderId' => 'BANNED-ORDER']))->assertForbidden()->assertJsonPath('status', 'BLOCKED');
    $this->postJson('/api/v1/payments', ['orderId' => 'ZERO', 'amount' => 0])->assertStatus(400);
    $this->postJson('/api/v1/payments', ['orderId' => 'BAD', 'amount' => '1.234'])->assertStatus(400);
});

it('disables legacy merchant headers by default and permits explicitly configured migration creation only', function () {
    config(['paykr.allow_legacy_merchant_header' => true]);
    $id = $this->withHeader('X-Merchant-Id', $this->merchant->id)->postJson('/api/v1/payments', ['orderId' => 'LEGACY', 'amount' => 10])->assertCreated()->json('paymentId');
    $this->getJson('/api/v1/payments/'.$id)->assertUnauthorized();
    $this->merchant->update(['status' => 'SUSPENDED']);
    $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', ['orderId' => 'SUSPENDED', 'amount' => 10])->assertForbidden();
});

it('limits each authenticated key and IP independently to sixty requests per minute', function () {
    $this->withHeaders(['X-Api-Key' => $this->pk, 'Origin' => 'https://shop.example.com']);
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', [])->assertStatus(400);
    }
    $this->postJson('/api/v1/payments', [])->assertStatus(429);
    $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', [])->assertStatus(400);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->withHeader('X-Api-Key', $this->pk)->postJson('/api/v1/payments', [])->assertStatus(400);
});

it('accepts 200 creations across twenty merchants within a minute without a shared IP bottleneck', function () {
    $started = microtime(true);
    for ($merchantIndex = 0; $merchantIndex < 20; $merchantIndex++) {
        $merchant = Merchant::factory()->create();
        $key = 'sk_live_'.bin2hex(random_bytes(32));
        app(TenantContext::class)->run($merchant->id, function () use ($key) {
            MerchantApiKey::factory()->create(['key_hash' => hash('sha256', $key), 'type' => 'secret', 'mode' => 'live']);
            PaymentReceivingAccount::factory()->active()->create();
        });
        for ($order = 0; $order < 10; $order++) {
            $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/payments', ['orderId' => 'LOAD-'.$order, 'amount' => '10'])->assertCreated();
        }
        expect(app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::count()))->toBe(10);
    }
    expect(microtime(true) - $started)->toBeLessThan(60.0);
});

it('creates enabled non-UPI payments and refuses disabled methods', function (string $method, string $mode, string $flag) {
    $this->withHeader('X-Api-Key', $this->sk);
    $body = ['orderId' => 'METHOD-ORDER', 'amount' => '10', 'method' => $method];
    $this->postJson('/api/v1/payments', $body)->assertJsonPath('code', 'PAYMENT_METHOD_DISABLED');
    app(TenantContext::class)->run($this->merchant->id, fn () => MerchantPaymentSettings::factory()->create(['selling_mode' => $mode, $flag => true]));
    $this->postJson('/api/v1/payments', $body)->assertCreated()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('method', $method);
})->with([['COD', 'ONLINE', 'cod_enabled'], ['CASH_COUNTER', 'OFFLINE', 'cash_enabled'], ['CARD_COUNTER', 'OFFLINE', 'card_enabled']]);

it('returns a successful order without creating another charge', function () {
    $payment = app(TenantContext::class)->run($this->merchant->id, fn () => PaymentSession::factory()->create(['status' => 'SUCCESS', 'order_id' => 'PAID-ORDER']));
    $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', ['orderId' => 'PAID-ORDER', 'amount' => '10'])->assertOk()->assertJsonPath('paymentId', $payment->id);
    expect(PaymentSession::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses new UPI payments when no receiving account is active', function () {
    app(TenantContext::class)->run($this->merchant->id, fn () => PaymentReceivingAccount::query()->update(['is_active' => false]));
    $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', ['orderId' => 'NO-ACCOUNT', 'amount' => '10'])->assertJsonPath('code', 'NO_ACTIVE_RECEIVING_ACCOUNT');
    expect(PaymentSession::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses suspended merchants even when legacy merchant headers are enabled', function () {
    config(['paykr.allow_legacy_merchant_header' => true]);
    $this->merchant->update(['status' => 'SUSPENDED']);
    expect(fn () => app(ApiKeyAuthenticator::class)->authenticate(null, null, false, $this->merchant->id))->toThrow(ValidationException::class, 'MERCHANT_SUSPENDED');
});

it('returns COD receipt details through both payment and order lookups', function (string $operation) {
    app(TenantContext::class)->run($this->merchant->id, function () use ($operation) {
        $customer = Customer::factory()->create();
        $payment = PaymentSession::factory()->create(['method' => 'COD', 'status' => 'SUCCESS', 'completed_at' => now(), 'customer_id' => $customer->id]);
        $result = app(PaymentApiService::class)->execute(new PaymentApiData(new ApiIdentity($this->merchant->id, true, false), $operation, id: $payment->id, orderId: $payment->order_id));
        expect($result->data['isCod'])->toBeTrue();
        expect($result->data['placedAt'])->not->toBeNull();
        expect($result->data['customer']['id'])->toBe($customer->id);
    });
})->with(['cod', 'order-cod']);

it('blocks merchant-blocked customers and rejects another device with a live customer payment', function () {
    $customer = Customer::factory()->create();
    app(TenantContext::class)->run($this->merchant->id, function () use ($customer) {
        MerchantCustomerBlock::create(['customer_id' => $customer->id, 'blocked_by' => User::factory()->create()->id, 'reason' => 'Merchant review']);
    });
    $body = ['orderId' => 'CUSTOMER-ORDER', 'amount' => '10', 'customerId' => $customer->id];
    $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', $body)->assertJsonPath('code', 'CUSTOMER_BLOCKED_BY_MERCHANT');
    app(TenantContext::class)->run($this->merchant->id, function () use ($customer) {
        MerchantCustomerBlock::query()->delete();
        PaymentSession::factory()->active()->create(['customer_id' => $customer->id, 'device_id' => Device::factory()]);
    });
    $this->postJson('/api/v1/payments', $body)->assertStatus(409);
});

it('rolls back a switch to UPI when there is no active receiving account', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        PaymentReceivingAccount::query()->update(['is_active' => false]);
        $payment = PaymentSession::factory()->active()->create(['method' => 'COD']);
        expect(fn () => app(PaymentMethodService::class)->select($payment->id, PaymentMethod::UPI, new Actor(ActorType::SYSTEM)))->toThrow(ValidationException::class, 'NO_ACTIVE_RECEIVING_ACCOUNT');
        expect($payment->fresh()->method->value)->toBe('COD');
    });
});

it('does not disclose a new token when another request registered the device first', function () {
    $dispatcher = PaymentReceivingAccount::getEventDispatcher();
    PaymentReceivingAccount::setEventDispatcher(clone $dispatcher);
    $reads = 0;
    PaymentReceivingAccount::retrieved(function () use (&$reads) {
        if (++$reads === 2) {
            Device::factory()->create(['legacy_id' => 'concurrent-browser']);
        }
    });
    try {
        $this->withHeader('X-Api-Key', $this->sk)->postJson('/api/v1/payments', ['orderId' => 'CONCURRENT-DEVICE', 'amount' => '10', 'deviceId' => 'concurrent-browser'])->assertCreated()->assertJsonMissingPath('deviceToken');
        expect(Device::where('legacy_id', 'concurrent-browser')->count())->toBe(1);
    } finally {
        PaymentReceivingAccount::setEventDispatcher($dispatcher);
    }
});
