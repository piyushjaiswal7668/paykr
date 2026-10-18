<?php

declare(strict_types=1);

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\DeveloperTools;
use App\Domain\Management\Services\MerchantOperations;
use App\Domain\Management\Services\PlatformOperations;
use App\Domain\Management\Services\PlatformSettings;
use App\Domain\Management\Services\StoreSettings;
use App\Domain\Management\Services\TeamManagement;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AndroidDevice;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantBranding;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;
use App\Models\MerchantTestMode;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    $this->merchant = Merchant::factory()->create();
    $this->user = User::factory()->create();
    $this->merchant->users()->attach($this->user, ['role' => 'owner']);
    $this->command = fn (string $operation, ?string $id = null, array $data = []) => new PanelCommand($this->user->id, $this->merchant->id, $operation, $id, $data, '192.0.2.42');
});

it('creates and manages receiving accounts atomically, refusing busy accounts and foreign records', function () {
    $service = app(MerchantOperations::class);
    $service->execute(($this->command)('account-create', data: ['upi_id' => 'example@ybl', 'payee_name' => 'Test Store', 'is_merchant_enabled' => true]));
    app(TenantContext::class)->run($this->merchant->id, function () use ($service) {
        $account = PaymentReceivingAccount::sole();
        $service->execute(($this->command)('account-activate', $account->id));
        expect($account->refresh()->is_active)->toBeTrue();
        $account->update(['is_slot_busy' => true]);
        expect(fn () => $service->execute(($this->command)('account-delete', $account->id)))->toThrow(ValidationException::class);
        $account->update(['is_slot_busy' => false]);
        $service->execute(($this->command)('account-deactivate', $account->id));
        $service->execute(($this->command)('account-delete', $account->id));
        expect(PaymentReceivingAccount::count())->toBe(0);
        expect(AuditLog::where('action', 'ACCOUNT_CREATE')->sole()->ip_address)->toBe('192.0.2.42');
    });
    $other = Merchant::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => PaymentReceivingAccount::factory()->create());
    expect(fn () => $service->execute(($this->command)('account-activate', $foreign->id)))->toThrow(ValidationException::class);
});

it('blocks only this store and resolves its tickets', function () {
    $customer = Customer::factory()->create();
    $other = Merchant::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => PaymentSession::factory()->active()->create(['customer_id' => $customer->id, 'method' => 'CASH_COUNTER']));
    app(TenantContext::class)->run($this->merchant->id, function () use ($customer) {
        $payment = PaymentSession::factory()->active()->create(['customer_id' => $customer->id, 'method' => 'CASH_COUNTER']);
        $service = app(MerchantOperations::class);
        $service->execute(($this->command)('customer-block', $customer->id, ['reason' => 'Merchant review requested']));
        expect($payment->refresh()->status->value)->toBe('EXPIRED');
        expect($customer->refresh()->status->value)->toBe('ACTIVE');
        expect(MerchantCustomerBlock::count())->toBe(1);
        $service->execute(($this->command)('customer-unblock', $customer->id));
        expect(MerchantCustomerBlock::count())->toBe(0);
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->id, 'payment_id' => $payment->id]);
        $service->execute(($this->command)('ticket-resolve', $ticket->id));
        expect($ticket->refresh()->status->value)->toBe('RESOLVED');
    });
    expect(app(TenantContext::class)->run($other->id, fn () => $foreign->fresh()->status->value))->toBe('ACTIVE');
});

it('saves branding and derives enabled methods without persisting injected fields', function () {
    $service = app(StoreSettings::class);
    $service->save(($this->command)('branding', data: ['app_name' => 'Store Pay', 'primary_color' => '#123456', 'header_theme' => 'solid', 'support_emails' => ['help@example.test'], 'support_mobiles' => ['919999999999'], 'merchant_id' => 'wrong', 'webhook_secret' => 'must-not-save']));
    $service->save(($this->command)('methods', data: ['selling_mode' => 'OFFLINE', 'cod_enabled' => true, 'cash_enabled' => true, 'card_enabled' => true, 'counter_instructions' => 'Visit the till', 'card_counter_instructions' => 'Use the card terminal']));
    app(TenantContext::class)->run($this->merchant->id, function () {
        expect(MerchantBranding::sole()->app_name)->toBe('Store Pay');
        expect(MerchantPaymentSettings::sole()->enabled_payment_methods)->toBe(['UPI', 'CASH_COUNTER', 'CARD_COUNTER']);
        expect(AuditLog::count())->toBe(2);
    });
});

it('issues rotates and revokes API keys without logging credentials', function () {
    $tools = app(DeveloperTools::class);
    $issued = $tools->execute(($this->command)('key-issue', data: ['type' => 'secret', 'mode' => 'test']));
    $rotated = $tools->execute(($this->command)('key-rotate', $issued['id']));
    expect($issued['key'])->toStartWith('sk_test_')->not->toBe($rotated['key']);
    $tools->execute(($this->command)('key-revoke', $rotated['id']));
    app(TenantContext::class)->run($this->merchant->id, function () use ($issued) {
        expect(MerchantApiKey::whereNull('revoked_at')->count())->toBe(0);
        expect(json_encode(AuditLog::all()->toArray()))->not->toContain($issued['key']);
    });
    expect(fn () => $tools->execute(($this->command)('key-rotate', $issued['id'])))->toThrow(ValidationException::class);
});

it('configures webhooks and queues explicit test deliveries and retries', function () {
    $tools = app(DeveloperTools::class);
    $tools->execute(($this->command)('webhook-settings', data: ['webhook_url' => 'https://receiver.example/hook', 'allowed_origins' => ['https://store.example/']]));
    $secret = $tools->execute(($this->command)('webhook-rotate'))['secret'];
    expect($tools->execute(($this->command)('webhook-reveal'))['secret'])->toBe($secret);
    $result = $tools->execute(($this->command)('webhook-test'));
    $tools->execute(($this->command)('webhook-resend', $result['deliveryId']));
    app(TenantContext::class)->run($this->merchant->id, function () use ($secret) {
        expect(PaymentSession::sole()->is_test)->toBeTrue();
        expect(WebhookDelivery::sole()->attempt_count)->toBe(0);
        expect(json_encode(AuditLog::all()->toArray()))->not->toContain($secret);
    });
    expect($this->merchant->refresh()->allowed_origins)->toBe(['https://store.example']);
    expect(fn () => $tools->execute(($this->command)('webhook-settings', data: ['webhook_url' => null, 'allowed_origins' => ['https://store.example/path']])))->toThrow(ValidationException::class);
});

it('pairs for a manager, previews SMS without attributing, and unpairs only their device', function () {
    $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => 'manager']);
    $tools = app(DeveloperTools::class);
    $qr = $tools->execute(($this->command)('android-pair'));
    expect($qr['merchantId'])->toBe($this->merchant->id)->and($qr['token'])->toStartWith('pair_');
    $parsed = $tools->execute(($this->command)('parse-sms', data: ['text' => 'INR 10 credited UTR 123456789012']));
    expect($parsed)->toMatchArray(['amountPaise' => 1000, 'reference' => '123456789012', 'attributed' => false]);
    app(TenantContext::class)->run($this->merchant->id, function () use ($tools) {
        $device = AndroidDevice::factory()->create();
        $tools->execute(($this->command)('android-unpair', $device->id));
        expect($device->refresh()->is_active)->toBeFalse();
        expect(PaymentSession::count())->toBe(0);
    });
    expect(fn () => $tools->execute(($this->command)('key-issue', data: ['type' => 'secret', 'mode' => 'test'])))->toThrow(ValidationException::class);
});

it('limits team management to owners and preserves the last owner', function () {
    $service = app(TeamManagement::class);
    $result = $service->execute(($this->command)('invite', data: ['name' => 'Cashier', 'email' => 'cashier@example.test', 'role' => 'cashier']));
    expect($result)->toHaveKey('invitationToken');
    $service->execute(($this->command)('role', $result['userId'], ['role' => 'manager']));
    $service->execute(($this->command)('remove', $result['userId']));
    expect($this->merchant->users()->count())->toBe(1);
    expect(fn () => $service->execute(($this->command)('remove', $this->user->id)))->toThrow(ValidationException::class);
    expect(fn () => $service->execute(($this->command)('role', $this->user->id, ['role' => 'cashier'])))->toThrow(ValidationException::class);
});

it('enables bounded test mode and refuses live or closed payment injection', function () {
    $tools = app(DeveloperTools::class);
    $data = ['source' => 'SMS', 'sender' => 'VM-HDFCBK', 'text' => 'INR 1 credited UTR 123456789012', 'received_at' => now()->toISOString()];
    expect(fn () => $tools->execute(($this->command)('simulate', 'unknown', $data)))->toThrow(ValidationException::class);
    $tools->execute(($this->command)('test-enable'));
    app(TenantContext::class)->run($this->merchant->id, function () use ($tools, $data) {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $payment = PaymentSession::factory()->forAccount($account)->active()->create(['is_test' => true]);
        $account->update(['is_slot_busy' => true, 'current_active_session_id' => $payment->id]);
        $result = $tools->execute(($this->command)('simulate', $payment->id, $data));
        expect($result)->toHaveKeys(['status']);
        expect(AuditLog::where('action', 'SIMULATE')->count())->toBe(1);
        $payment->update(['is_test' => false]);
        expect(fn () => $tools->execute(($this->command)('simulate', $payment->id, $data)))->toThrow(ModelNotFoundException::class);
        $payment->update(['is_test' => true, 'status' => 'SUCCESS']);
        expect(fn () => $tools->execute(($this->command)('simulate', $payment->id, $data)))->toThrow(ValidationException::class);
    });
    $tools->execute(($this->command)('test-disable'));
    expect(app(TenantContext::class)->run($this->merchant->id, fn () => MerchantTestMode::sole()->expires_at))->toBeNull();
});

it('restricts platform mutations and records administrator identity', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $platform = app(PlatformOperations::class);
    $command = fn ($op, $id = null, $data = []) => new PanelCommand($admin->id, '', $op, $id, $data, '192.0.2.10');
    expect(fn () => $platform->execute(($this->command)('merchant-suspend', $this->merchant->id)))->toThrow(ValidationException::class);
    $created = $platform->execute($command('merchant-save', data: ['business_name' => 'New store', 'slug' => 'new-store', 'contact_email' => 'store@example.test', 'owner_name' => 'Owner', 'owner_email' => 'owner@example.test']));
    expect($created)->toHaveKeys(['merchantId', 'invitationToken']);
    $platform->execute($command('merchant-save', $created['merchantId'], ['business_name' => 'Edited store', 'slug' => 'new-store', 'contact_email' => 'store@example.test']));
    $platform->execute($command('merchant-suspend', $created['merchantId']));
    expect(Merchant::find($created['merchantId'])->status->value)->toBe('SUSPENDED');
    $platform->execute($command('merchant-activate', $created['merchantId']));
    expect($platform->execute($command('impersonate', $created['merchantId']))['userId'])->toBe($created['userId']);
    $customer = Customer::factory()->create();
    $platform->execute($command('customer-ban', $customer->id, ['notes' => 'Confirmed suspicious activity']));
    expect($customer->refresh()->status->value)->toBe('BANNED');
    $platform->execute($command('customer-unban', $customer->id, ['notes' => 'Review complete']));
    expect($customer->refresh()->status->value)->toBe('ACTIVE');
    app(TenantContext::class)->run($this->merchant->id, function () use ($platform, $command) {
        $payment = PaymentSession::factory()->active()->create(['method' => 'CASH_COUNTER']);
        $platform->execute($command('payment-close', $payment->id));
        expect($payment->refresh()->status->value)->toBe('CANCELLED');
        $key = MerchantApiKey::factory()->create();
        $platform->execute($command('key-revoke', $key->id));
        expect($key->refresh()->revoked_at)->not->toBeNull();
        $delivery = WebhookDelivery::factory()->create(['payment_id' => $payment->id]);
        $platform->execute($command('webhook-resend', $delivery->id));
        expect($delivery->refresh()->attempt_count)->toBe(0);
    });
    $logs = app(TenantContext::class)->runUnscoped(fn () => AuditLog::where('action', 'like', 'ADMIN_%')->get());
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log->actor_id)->toBe($admin->id)->and($log->ip_address)->toBe('192.0.2.10');
    }
});

it('operates the WhatsApp sender through the authenticated sidecar without leaking unrelated fields', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    config(['paykr.sidecars.whatsapp_enabled' => true, 'paykr.internal_token' => 'test-internal']);
    Http::fake(['*' => Http::response(['status' => 'CONNECTED', 'delivered' => true, 'pairingCode' => '12345678', 'sensitiveUnexpected' => 'never-show'])]);
    foreach (['whatsapp-connect', 'whatsapp-pairing-code', 'whatsapp-test', 'whatsapp-disconnect'] as $operation) {
        $result = app(PlatformOperations::class)->execute(new PanelCommand($admin->id, '', $operation, data: ['phone' => '919999999999', 'message' => 'Explicit test message']));
        expect($result)->not->toHaveKey('sensitiveUnexpected');
    }
    Http::assertSentCount(4);
});

it('stores platform defaults and publishes an APK only inside isolated release storage', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $settings = app(PlatformSettings::class);
    $settings->save($admin->id, ['app_name' => 'Platform Pay', 'primary_color' => '#123456', 'otp_max_attempts' => 3, 'otp_resend_cooldown_seconds' => 60, 'otp_sends_per_mobile_10_minutes' => 2, 'otp_sends_per_ip_hour' => 8], '192.0.2.10');
    expect(PlatformSettings::value('app_name', 'fallback'))->toBe('Platform Pay');
    expect(PlatformSettings::value('missing', 'fallback'))->toBe('fallback');
    $original = storage_path();
    $temporary = sys_get_temp_dir().'/paykr-apk-test-'.bin2hex(random_bytes(5));
    mkdir($temporary.'/app/private/uploads', 0700, true);
    app()->useStoragePath($temporary);
    try {
        $file = $temporary.'/app/private/uploads/test.apk';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('AndroidManifest.xml', 'test fixture');
        $zip->addFromString('classes.dex', 'test fixture');
        $zip->close();
        $result = $settings->publishApk($admin->id, $file, '9.0.0', '192.0.2.10');
        expect($result['sha256'])->toBe(hash_file('sha256', $file));
        expect(is_file($temporary.'/app/private/releases/paykr-sms-gateway.apk'))->toBeTrue();
        expect(fn () => $settings->publishApk($admin->id, __FILE__, '9.0.1', null))->toThrow(ValidationException::class);
    } finally {
        app()->useStoragePath($original);
        File::deleteDirectory($temporary);
    }
});

it('declines counter payments and clears only the selected merchants waiting queue', function () {
    $service = app(MerchantOperations::class);
    $other = Merchant::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => PaymentSession::factory()->create(['status' => 'QUEUED', 'queue_position' => 1]));
    app(TenantContext::class)->run($this->merchant->id, function () use ($service) {
        $counter = PaymentSession::factory()->active()->create(['method' => 'CARD_COUNTER', 'expires_at' => null]);
        $service->execute(($this->command)('counter-decline', $counter->id, ['reason' => 'Card payment declined by customer']));
        expect($counter->refresh()->status->value)->toBe('FAILED');
        expect(fn () => $service->execute(($this->command)('counter-decline', $counter->id, ['reason' => 'Repeated request'])))->toThrow(ValidationException::class);
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $queue = app(SlotQueueService::class);
        $active = PaymentSession::factory()->forAccount($account)->create();
        $waiting = PaymentSession::factory()->forAccount($account)->create();
        $actor = new Actor(ActorType::SYSTEM);
        $queue->enqueueOrActivate($active->id, $account->id, $actor);
        $queue->enqueueOrActivate($waiting->id, $account->id, $actor);
        $service->execute(($this->command)('clear-queue'));
        expect($waiting->refresh()->status->value)->toBe('CANCELLED');
        expect($active->refresh()->status->value)->toBe('ACTIVE');
    });
    expect(app(TenantContext::class)->run($other->id, fn () => $foreign->fresh()->status->value))->toBe('QUEUED');
});

it('lets a manager configure Gmail and start OAuth without exposing stored secrets', function () {
    $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => 'manager']);
    $tools = app(DeveloperTools::class);
    $tools->execute(($this->command)('gmail-credentials', data: ['client_id' => 'test-client', 'client_secret' => 'never-log-client-secret']));
    $result = $tools->execute(($this->command)('gmail-connect'));
    expect($result['redirect'])->toStartWith('https://accounts.google.com/');
    expect(json_encode($result))->not->toContain('never-log-client-secret');
    $tools->execute(($this->command)('gmail-disconnect'));
    app(TenantContext::class)->run($this->merchant->id, function () {
        expect(json_encode(AuditLog::all()->toArray()))->not->toContain('never-log-client-secret');
        expect(MerchantGmailConnection::first()->status->value)->toBe('DISCONNECTED');
    });
});
