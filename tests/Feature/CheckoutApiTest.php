<?php

declare(strict_types=1);
use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantBranding;
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
