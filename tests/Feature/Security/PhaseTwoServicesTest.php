<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\BanEvidenceService;
use App\Domain\Customers\Services\BanService;
use App\Domain\Devices\Services\DeviceAccessService;
use App\Domain\Observers\Data\MessagesHealth;
use App\Domain\Observers\Services\UpiHealthService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Models\AndroidDevice;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantCustomerBlock;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    $this->freezeTime();
    Bus::fake([DeliverWebhook::class]);
});
afterEach(fn () => RefreshDatabaseState::$migrated = false);

function phaseTwoAdmin(): Actor
{
    $admin = User::factory()->create(['is_platform_admin' => true]);

    return new Actor(ActorType::PLATFORM_ADMIN, $admin->id);
}

it('manually bans globally once with terminal webhooks and restores identity without reopening orders', function () {
    $actor = phaseTwoAdmin();
    $customer = Customer::factory()->create();
    $merchants = Merchant::factory()->count(2)->create(['webhook_url' => 'https://merchant.example/hook']);
    $payments = [];
    foreach ($merchants as $merchant) {
        app(TenantContext::class)->run($merchant->id, function () use ($customer, &$payments) {
            $account = PaymentReceivingAccount::factory()->active()->create();
            foreach (range(1, 2) as $i) {
                $payment = PaymentSession::factory()->forAccount($account)->create(['customer_id' => $customer->id]);
                app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
                $payments[] = $payment;
            }
        });
    }
    $bans = app(BanService::class);
    $bans->ban($customer->id, $actor, 'Reviewed by admin', 'Firefox');
    $bans->ban($customer->id, $actor, 'repeat');
    expect($customer->refresh()->status)->toBe(CustomerStatus::BANNED);
    app(TenantContext::class)->runUnscoped(function () use ($actor) {
        expect(PaymentSession::where('status', PaymentStatus::EXPIRED)->count())->toBe(4);
        expect(WebhookDelivery::count())->toBe(4);
        expect(PaymentReceivingAccount::where('is_slot_busy', true)->count())->toBe(0);
        expect(SecurityEvent::where('event_type', 'MANUAL_BAN')->count())->toBe(1);
        expect(AuditLog::where('action', 'CUSTOMER_MANUALLY_BANNED')->first()->actor_id)->toBe($actor->id);
    });
    $evidence = app(BanEvidenceService::class)->get($customer->id, $actor);
    expect($evidence['banDetails']['banType'])->toBe('MANUAL');
    expect($evidence['rawEvidence'])->toBe('Reviewed by admin');
    expect($evidence['customer']['totalOrders'])->toBe(4);
    expect($evidence['customer']['totalSpentRupees'])->toBe('0.00');
    expect(app(BanEvidenceService::class)->banned($actor, $customer->mobile_number))->toHaveCount(1);
    expect(app(BanEvidenceService::class)->banned($actor, 'no-match'))->toBe([]);
    $bans->unban($customer->id, $actor, 'Restored');
    $bans->unban($customer->id, $actor);
    expect($customer->refresh()->status)->toBe(CustomerStatus::ACTIVE)->and($customer->ban_reason)->toBeNull()->and($customer->unbanned_at)->not->toBeNull();
    app(TenantContext::class)->runUnscoped(function () {
        expect(SecurityEvent::where('event_type', 'USER_UNBANNED')->count())->toBe(1);
        expect(PaymentSession::where('status', PaymentStatus::EXPIRED)->count())->toBe(4);
    });
    expect(app(TenantContext::class)->isUnscoped())->toBeFalse();
});

it('rejects merchant and forged admin bans or evidence reads and rolls back a ban atomically', function () {
    $customer = Customer::factory()->create();
    foreach ([new Actor(ActorType::MERCHANT_USER), new Actor(ActorType::PLATFORM_ADMIN, User::factory()->create()->id)] as $actor) {
        expect(fn () => app(BanService::class)->ban($customer->id, $actor))->toThrow(ValidationException::class, 'FORBIDDEN');
        expect(fn () => app(BanService::class)->unban($customer->id, $actor))->toThrow(ValidationException::class, 'FORBIDDEN');
        expect(fn () => app(BanEvidenceService::class)->get($customer->id, $actor))->toThrow(ValidationException::class, 'FORBIDDEN');
        expect(fn () => app(BanEvidenceService::class)->banned($actor))->toThrow(ValidationException::class, 'FORBIDDEN');
    }
    $admin = phaseTwoAdmin();
    expect(app(BanEvidenceService::class)->get('missing', $admin))->toBeNull();
    expect(fn () => app(BanService::class)->ban('missing', $admin))->toThrow(ValidationException::class, 'NOT_FOUND');
    expect(fn () => app(BanService::class)->unban('missing', $admin))->toThrow(ValidationException::class, 'NOT_FOUND');
    try {
        DB::transaction(function () use ($customer, $admin) {
            app(BanService::class)->ban($customer->id, $admin);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($customer->refresh()->status)->toBe(CustomerStatus::ACTIVE);
    app(TenantContext::class)->runUnscoped(fn () => expect(SecurityEvent::count())->toBe(0));
});

it('requires the matching hashed device token for live bound sessions, including imported public IDs', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $account = PaymentReceivingAccount::factory()->active()->create();
        $payment = PaymentSession::factory()->forAccount($account)->create();
        $access = app(DeviceAccessService::class);
        expect($access->check($payment->id, null)['boundDeviceId'])->toBeNull();
        $binding = $access->check($payment->id, 'legacy-browser-123');
        expect($binding['boundDeviceId'])->toBe('legacy-browser-123');
        $device = Device::byPublicId('legacy-browser-123')->firstOrFail();
        expect($device->device_token_hash)->toBe(hash('sha256', $binding['deviceToken']));
        foreach ([[null, null], ['legacy-browser-123', null], ['legacy-browser-123', 'wrong'], ['other-device', 'wrong']] as [$id, $token]) {
            expect(fn () => $access->check($payment->id, $id, $token))->toThrow(ValidationException::class, 'DEVICE_SESSION_MISMATCH');
        }
        expect($access->check($payment->id, 'legacy-browser-123', $binding['deviceToken'])['allowed'])->toBeTrue();
        $second = PaymentSession::factory()->forAccount($account)->create();
        expect(fn () => $access->check($second->id, $device->id, null))->toThrow(ValidationException::class, 'DEVICE_NOT_AUTHORIZED');
        expect($access->check($second->id, $device->id, $binding['deviceToken'])['allowed'])->toBeTrue();
        $device->update(['status' => 'REVOKED']);
        expect(fn () => $access->check($payment->id, $device->id, $binding['deviceToken']))->toThrow(ValidationException::class, 'DEVICE_REVOKED');
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
        expect($access->check($payment->id, null)['isTerminal'])->toBeTrue();
        $fresh = PaymentSession::factory()->forAccount($account)->create();
        expect(fn () => $access->check($fresh->id, ''))->toThrow(ValidationException::class, 'DEVICE_REQUIRED');
        expect(fn () => $access->check('missing', null))->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND');
        app(TenantContext::class)->run(Merchant::factory()->create()->id, fn () => expect(fn () => $access->check($fresh->id, null))->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND'));
    });
});

it('reports merchant-specific UPI health from fresh successful polls and active SMS heartbeats', function () {
    $health = app(UpiHealthService::class);
    expect(fn () => $health->check())->toThrow(ValidationException::class, 'FORBIDDEN');
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($health, $merchant) {
        expect($health->check()['tier'])->toBe('RED');
        $settings = MerchantPaymentSettings::factory()->create(['selling_mode' => 'ONLINE', 'cod_enabled' => true]);
        expect($health->check()['recommendedAlternative'])->toBe('CASH');
        $gmail = MerchantGmailConnection::factory()->create(['refresh_token' => 'test-token', 'last_successful_fetch' => null]);
        expect($health->check()['gmailHealthy'])->toBeFalse();
        $gmail->update(['last_successful_fetch' => now()]);
        expect($health->check()['tier'])->toBe('YELLOW');
        $android = AndroidDevice::factory()->create(['is_active' => true, 'last_heartbeat_at' => now()]);
        expect($health->check()['tier'])->toBe('GREEN');
        $android->update(['is_active' => false]);
        expect($health->check()['tier'])->toBe('YELLOW');
        $android->update(['is_active' => true, 'last_heartbeat_at' => now()->subMinutes(5)]);
        $gmail->update(['last_successful_fetch' => now()->subMinutes(5)]);
        expect($health->check()['tier'])->toBe('RED');
        $settings->update(['selling_mode' => 'OFFLINE', 'cash_enabled' => false]);
        expect($health->check()['recommendedAlternative'])->toBeNull();
        $settings->update(['cash_enabled' => true]);
        expect($health->check()['recommendedAlternative'])->toBe('CASH');
        $snapshot = new MessagesHealth($merchant->id, now()->toImmutable(), true, 'CONNECTED', 'BACKGROUND', true);
        expect($health->check($snapshot)['smsHealthy'])->toBeTrue();
        expect($health->check(new MessagesHealth('other', now()->toImmutable(), true, 'CONNECTED', 'BACKGROUND', true))['smsHealthy'])->toBeFalse();
        expect($health->check(new MessagesHealth($merchant->id, now()->toImmutable()->subMinutes(5), true, 'CONNECTED', 'BACKGROUND', true))['smsHealthy'])->toBeFalse();
        expect($health->check(new MessagesHealth($merchant->id, now()->toImmutable(), true, 'CONNECTED', 'BACKGROUND', false, now()->toImmutable()))['smsHealthy'])->toBeTrue();
        expect($health->check(new MessagesHealth($merchant->id, now()->toImmutable(), false, 'CONNECTED', 'BACKGROUND', true))['smsHealthy'])->toBeFalse();
        $gmail->update(['last_successful_fetch' => now(), 'refresh_token' => ' ']);
        expect($health->check()['gmailHealthy'])->toBeFalse();
        app(TenantContext::class)->run(Merchant::factory()->create()->id, fn () => expect($health->check($snapshot)['tier'])->toBe('RED'));
    });
});

it('prevents banned or merchant-blocked customers from starting or completing new payments', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $account = PaymentReceivingAccount::factory()->active()->create();
        $customer = Customer::factory()->create(['status' => 'BANNED']);
        $payment = PaymentSession::factory()->forAccount($account)->create(['customer_id' => $customer->id]);
        $queue = app(SlotQueueService::class);
        $actor = new Actor(ActorType::SYSTEM);
        expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class, 'CUSTOMER_BANNED');
        $customer->update(['status' => 'ACTIVE']);
        $block = MerchantCustomerBlock::factory()->create(['customer_id' => $customer->id]);
        expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class, 'CUSTOMER_BLOCKED_BY_MERCHANT');
        $block->delete();
        $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        $customer->update(['status' => 'BANNED']);
        expect(fn () => app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::SUCCESS, $actor))->toThrow(ValidationException::class, 'CUSTOMER_BANNED');
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('preserves automatic fraud evidence verbatim and counts successful spending using integer paise', function () {
    $admin = phaseTwoAdmin();
    $customer = Customer::factory()->create(['status' => 'BANNED', 'ban_reason' => 'FRAUDULENT_PAYMENT_INJECTION', 'banned_at' => now()]);
    $merchant = Merchant::factory()->create();
    $raw = "Original SMS\n₹200 credit\tUTR: 123456789012";
    app(TenantContext::class)->run($merchant->id, function () use ($customer, $raw) {
        $payment = PaymentSession::factory()->create(['customer_id' => $customer->id, 'status' => 'SUCCESS', 'amount_paise' => 20000]);
        SecurityEvent::factory()->create(['customer_id' => $customer->id, 'payment_session_id' => $payment->id,
            'event_type' => 'FRAUDULENT_PAYMENT_INJECTION', 'raw_message' => $raw, 'extracted_amount' => '₹200.00', 'extracted_reference' => '123456789012']);
    });
    $evidence = app(BanEvidenceService::class)->get($customer->id, $admin);
    expect($evidence['rawEvidence'])->toBe($raw)->and($evidence['history'][0]['rawMessage'])->toBe($raw);
    expect($evidence['banDetails']['banType'])->toBe('AUTOMATIC');
    expect($evidence['customer']['totalSpentPaise'])->toBe(20000)->and($evidence['customer']['totalSpentRupees'])->toBe('200.00');
    expect($evidence['session']['state'])->toBe('SUCCESS');
    expect(app(BanEvidenceService::class)->banned($admin)[0]['orderId'])->toBe($evidence['session']['orderId']);
});
