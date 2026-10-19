<?php

declare(strict_types=1);
use App\Domain\Checkout\Data\CheckoutData;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantPaymentSettings;
use App\Models\MerchantTestMode;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);
beforeEach(function () {
    Cache::flush();
    Bus::fake();
    $this->merchant = Merchant::factory()->create(['webhook_url' => 'https://shop.example.com/hook', 'webhook_secret' => 'test-secret']);
    $this->customer = Customer::factory()->create();
    $this->customerToken = 'csess_'.str_repeat('a', 64);
    CustomerSession::factory()->create(['customer_id' => $this->customer->id, 'token_hash' => hash('sha256', $this->customerToken)]);
    $this->deviceToken = str_repeat('b', 64);
    $this->device = Device::factory()->create(['device_token_hash' => hash('sha256', $this->deviceToken)]);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->account = PaymentReceivingAccount::factory()->create(['merchant_id' => $this->merchant->id, 'is_active' => true]);
        MerchantPaymentSettings::factory()->create(['merchant_id' => $this->merchant->id, 'cod_enabled' => true]);
        $this->payment = PaymentSession::factory()->forAccount($this->account)->create(['device_id' => $this->device->id]);
    });
    $this->path = '/api/v1/checkout/'.$this->payment->id;
});
it('requires device proof for live checkout and customer authentication for binding', function () {
    $this->withHeader('X-Internal-Render', 'true')->postJson($this->path.'/cancel')->assertStatus(400);
    $this->withHeader('X-Device-Id', $this->device->id)->postJson($this->path.'/cancel')->assertStatus(409);
    $this->withHeader('X-Device-Token', $this->deviceToken)->postJson($this->path.'/bind-customer', ['customerId' => $this->customer->id])->assertUnauthorized();
    $this->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk()->assertJsonPath('status', 'ACTIVE');
    $this->getJson($this->path.'/state')->assertOk()->assertJsonPath('customer.id', $this->customer->id)->assertJsonPath('canClickPaymentDone', true);
    $this->postJson($this->path.'/payment-done')->assertOk()->assertJsonPath('status', 'PROCESSING');
    $this->postJson($this->path.'/payment-done')->assertOk()->assertJsonPath('status', 'PROCESSING');
    $this->postJson($this->path.'/cancel')->assertOk()->assertJsonPath('status', 'CANCELLED');
    $this->postJson($this->path.'/confirm-cod')->assertStatus(409);
});
it('uses deadlines for expiry hints and allowlists public branding and metadata', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        MerchantBranding::factory()->create(['merchant_id' => $this->merchant->id, 'app_name' => 'Safe Brand']);
        $this->merchant->forceFill(['legacy_settings' => ['gmailAccessToken' => 'SECRET', 'testMode' => true]])->save();
        $this->payment->update(['metadata' => ['nested' => ['gmailToken' => 'SECRET'], 'receivingAccountSnapshot' => ['token' => 'SECRET']]]);
    });
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk();
    $state = $this->postJson($this->path.'/expire')->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('branding.appName', 'Safe Brand');
    expect($state->getContent())->not->toContain('SECRET', 'gmailToken', 'testMode', 'receivingAccountSnapshot');
    $this->travel(301)->seconds();
    $this->getJson($this->path.'/state')->assertOk()->assertJsonPath('status', 'EXPIRED')->assertJsonPath('timeRemainingSeconds', 0);
    $this->postJson($this->path.'/payment-done')->assertStatus(400);
});
it('places COD atomically and gives counter payments no deadline', function () {
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken]);
    $this->postJson($this->path.'/confirm-cod')->assertOk()->assertJsonPath('status', 'SUCCESS')->assertJsonPath('method', 'COD')->assertJsonPath('verificationTag', 'COD_ORDER_PLACED');
    app(TenantContext::class)->run($this->merchant->id, function () {
        expect(WebhookDelivery::count())->toBe(1);
        MerchantPaymentSettings::first()->update(['selling_mode' => 'OFFLINE', 'cash_enabled' => true]);
        $this->payment = PaymentSession::factory()->forAccount($this->account)->create(['device_id' => $this->device->id]);
    });
    $path = '/api/v1/checkout/'.$this->payment->id;
    $this->postJson($path.'/select-method', ['method' => 'CASH_COUNTER'])->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('expiresAt', null);
    $this->postJson($path.'/confirm-cod')->assertStatus(422);
    $this->getJson($path.'/events')->assertStatus(410);
    $this->postJson($path.'/trigger-hook', ['action' => 'bad action'])->assertStatus(422);
    $this->postJson($path.'/trigger-hook', ['action' => 'OPEN_APP'])->assertOk();
});

it('restarts a live checkout only for its authenticated customer on a proven new device', function () {
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk();
    $other = Device::factory()->create(['device_token_hash' => hash('sha256', 'new-token')]);
    $this->withHeaders(['X-Device-Id' => $other->id, 'X-Device-Token' => 'wrong'])->postJson($this->path.'/end-and-restart-payment')->assertForbidden();
    $this->withHeader('X-Device-Token', 'new-token')->withToken('wrong-customer')->postJson($this->path.'/end-and-restart-payment')->assertUnauthorized();
    $response = $this->withToken($this->customerToken)->postJson($this->path.'/end-session-and-create-new')->assertCreated()->assertJsonPath('terminatedSessionIds', [$this->payment->id]);
    $this->getJson('/api/v1/checkout/'.$response->json('newSessionId').'/state')->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('deviceId', $other->id);
    $this->postJson($this->path.'/end-and-restart-payment')->assertStatus(409);
    $this->getJson($this->path.'/state')->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonMissingPath('customer');
});

it('returns conflict flags on the same device and retries without stealing another customer session', function () {
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk();
    $second = app(TenantContext::class)->run($this->merchant->id, fn () => PaymentSession::factory()->forAccount($this->account)->create(['device_id' => $this->device->id]));
    $path = '/api/v1/checkout/'.$second->id;
    $this->postJson($path.'/bind-customer')->assertOk()->assertJsonPath('status', 'INITIATED')->assertJsonPath('hasActiveSessionConflict', true);
    $this->postJson($path.'/retry-new-payment')->assertOk()->assertJsonPath('status', 'ACTIVE');
    $this->getJson($this->path.'/state')->assertOk()->assertJsonPath('status', 'CANCELLED');
    $this->get('/checkout/'.$second->id.'/qr.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->postJson($path.'/cancel')->assertOk();
    $this->getJson('/checkout/'.$second->id.'/qr.svg')->assertNotFound();
});

it('restricts simulation to authorized users with persisted test mode and test payments', function () {
    $this->postJson($this->path.'/simulate-observation', ['rawText' => 'Rs 1 credited UTR 123456789012'])->assertUnauthorized();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'sanctum')->postJson($this->path.'/simulate-observation', ['rawText' => 'Rs 1 credited UTR 123456789012'])->assertForbidden()->assertJsonPath('code', 'TEST_MODE_INACTIVE');
    app(TenantContext::class)->run($this->merchant->id, function () use ($admin) {
        $this->payment->update(['is_test' => true, 'status' => 'ACTIVE', 'expires_at' => now()->addMinutes(5)]);
        MerchantTestMode::factory()->create(['merchant_id' => $this->merchant->id, 'enabled_by' => $admin->id, 'enabled_at' => now()->subMinute(), 'expires_at' => now()->addHour()]);
    });
    $this->postJson($this->path.'/simulate-observation', ['rawText' => 'Rs 1 credited UTR 123456789012'])->assertOk()->assertJsonPath('simulation.isTest', true);
    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'sanctum')->postJson($this->path.'/simulate-observation', ['rawText' => 'Rs 1 credited UTR 123456789013'])->assertForbidden();
});

it('returns safe health and blocked-state notices', function () {
    $this->getJson('/api/v1/checkout/server-health?merchantId='.$this->merchant->id)->assertOk()->assertJsonPath('tier', 'RED');
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk();
    $this->customer->update(['status' => 'BANNED']);
    $this->getJson($this->path.'/state')->assertOk()->assertJsonPath('status', 'BLOCKED')->assertJsonPath('blockedNotice.title', 'FRAUDULENT ACTIVITY DETECTED');
    $this->postJson($this->path.'/payment-done')->assertForbidden();
});

it('uses the verified customer instead of unverified merchant details at first checkout binding', function () {
    $hint = Customer::factory()->create();
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['customer_id' => $hint->id]));
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken]);
    $this->postJson($this->path.'/bind-customer')->assertUnauthorized();
    $this->withToken($this->customerToken)->postJson($this->path.'/bind-customer')
        ->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('customer.id', $this->customer->id);
    $this->get('/checkout/'.$this->payment->id.'/qr.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertOk();
    $this->assertDatabaseHas('audit_logs', ['entity_id' => $this->payment->id, 'action' => 'CUSTOMER_AUTHENTICATED_AND_BOUND']);
});

it('does not replace an authenticated customer even when a conflict leaves the payment initiated', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        PaymentSession::factory()->forAccount($this->account)->active()->create([
            'customer_id' => $this->customer->id, 'device_id' => $this->device->id,
        ]);
    });
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])
        ->withToken($this->customerToken)->postJson($this->path.'/bind-customer')
        ->assertOk()->assertJsonPath('status', 'INITIATED');
    $other = Customer::factory()->create();
    $token = 'csess_'.str_repeat('d', 64);
    CustomerSession::factory()->create(['customer_id' => $other->id, 'token_hash' => hash('sha256', $token)]);
    $this->withToken($token)->postJson($this->path.'/bind-customer')->assertStatus(409)->assertJsonPath('code', 'SESSION_ALREADY_BOUND');
    $this->assertDatabaseHas('payment_sessions', ['id' => $this->payment->id, 'customer_id' => $this->customer->id]);
});

it('does not replace a customer on an already active payment', function () {
    $other = Customer::factory()->create();
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['customer_id' => $other->id, 'status' => 'ACTIVE', 'expires_at' => now()->addMinutes(5)]));
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])
        ->withToken($this->customerToken)->postJson($this->path.'/bind-customer')->assertStatus(409)->assertJsonPath('code', 'SESSION_ALREADY_BOUND');
    $this->assertDatabaseHas('payment_sessions', ['id' => $this->payment->id, 'customer_id' => $other->id]);
});

it('rejects unsafe checkout mutations at the service boundary', function (string $operation, string $status, bool $owned, bool $blocked, string $code) {
    app(TenantContext::class)->run($this->merchant->id, function () use ($status, $owned, $blocked) {
        $this->payment->update(['status' => $status, 'customer_id' => $owned ? $this->customer->id : null]);
        if ($blocked) {
            MerchantCustomerBlock::create(['customer_id' => $this->customer->id, 'reason' => 'Merchant decision', 'blocked_by' => User::factory()->create()->id]);
        }
    });
    try {
        app(CheckoutService::class)->execute(new CheckoutData($operation, $this->payment->id, $this->device->id, $this->deviceToken, $this->customerToken));
        $this->fail('Mutation should have been rejected');
    } catch (ValidationException $exception) {
        expect($exception->errorCode->value)->toBe($code);
    }
})->with([
    ['trigger-hook', 'FAILED', true, false, 'PAYMENT_CLOSED'],
    ['bind-customer', 'FAILED', true, false, 'PAYMENT_CLOSED'],
    ['bind-customer', 'INITIATED', false, true, 'CUSTOMER_BLOCKED_BY_MERCHANT'],
    ['cancel', 'INITIATED', true, true, 'CUSTOMER_BLOCKED_BY_MERCHANT'],
    ['retry-new-payment', 'INITIATED', false, false, 'FORBIDDEN'],
    ['retry-new-payment', 'FAILED', true, false, 'PAYMENT_CLOSED'],
    ['end-and-restart-payment', 'INITIATED', false, false, 'FORBIDDEN'],
]);

it('returns a generated QR and a COD receipt through the service', function () {
    $service = app(CheckoutService::class);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'ACTIVE', 'expires_at' => now()->addMinutes(5)]));
    $result = $service->execute(new CheckoutData('qr', $this->payment->id, $this->device->id, $this->deviceToken));
    expect($result->svg)->toContain('<svg');
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'SUCCESS', 'method' => 'COD', 'completed_at' => now()]));
    $result = $service->execute(new CheckoutData('cod', $this->payment->id));
    expect($result->data['isCod'])->toBeTrue()->and($result->data['placedAt'])->not->toBeNull();
});

it('activates authenticated counter checkout and lists enabled card payments', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        MerchantPaymentSettings::first()->update(['selling_mode' => 'OFFLINE', 'card_enabled' => true]);
        $this->payment->update(['method' => 'CARD_COUNTER']);
    });
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => $this->deviceToken])->withToken($this->customerToken)
        ->postJson($this->path.'/bind-customer')->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('enabledMethods', ['UPI', 'CARD_COUNTER']);
});

it('requires a device at the checkout service boundary and issues proof for a new browser', function () {
    $service = app(CheckoutService::class);
    expect(fn () => $service->execute(new CheckoutData('cancel', $this->payment->id)))->toThrow(ValidationException::class, 'DEVICE_REQUIRED');
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['device_id' => null]));
    $result = $service->execute(new CheckoutData('state', $this->payment->id, 'new-browser'));
    expect($result->data['deviceToken'])->toBeString()->not->toBeEmpty();
});

it('refuses simulation against a closed test payment', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->payment->update(['is_test' => true, 'status' => 'FAILED']);
        MerchantTestMode::create(['enabled_at' => now()->subMinute(), 'expires_at' => now()->addMinutes(10)]);
    });
    expect(fn () => app(CheckoutService::class)->execute(new CheckoutData('simulate-observation', $this->payment->id, userId: $admin->id)))->toThrow(ValidationException::class, 'SESSION_NOT_ACTIVE');
});

it('preserves other initiated checkouts already on the replacement device', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->payment->update(['customer_id' => $this->customer->id]);
        $other = PaymentSession::factory()->create(['customer_id' => $this->customer->id, 'device_id' => $this->device->id]);
        $result = app(CheckoutService::class)->execute(new CheckoutData('end-and-restart-payment', $this->payment->id, $this->device->id, $this->deviceToken, $this->customerToken));
        expect($result->data['terminatedSessionIds'])->toBe([$this->payment->id]);
        expect($other->fresh()->status->value)->toBe('INITIATED');
    });
});
