<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\ImportSqliteAction;
use App\Domain\Platform\Data\ImportSqliteData;
use App\Domain\Platform\Services\LegacyRowMapper;
use App\Domain\Shared\Errors\ImportException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\AndroidDevice;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;
use App\Models\MerchantTestMode;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use App\Models\TicketMessage;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\LegacyDatabase;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->legacy = new LegacyDatabase;
});
afterEach(function () {
    unset($this->legacy);
});

it('imports all legacy tables preserving IDs secrets payload bytes and row counts', function () {
    $before = hash_file('sha256', $this->legacy->path);
    $result = app(ImportSqliteAction::class)->handle(new ImportSqliteData($this->legacy->path));
    expect($result->counts)->toHaveCount(13);
    foreach ($result->counts as $count) {
        expect($count)->toBe(1);
    }
    expect(hash_file('sha256', $this->legacy->path))->toBe($before);
    $merchant = Merchant::firstOrFail();
    expect($merchant->id)->toBe($this->legacy->ids['merchant'])->and($merchant->webhook_secret)->toBe('legacy-webhook-secret');
    expect($merchant->updated_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-17 12:34:56.222');
    expect($merchant->getRawOriginal('legacy_settings'))->not->toContain('gmail-secret');
    expect($merchant->toArray())->not->toHaveKeys(['legacy_settings', 'webhook_secret']);
    $customer = Customer::firstOrFail();
    expect($customer->password_hash)->toBe('100000:salt:legacyhash')->and($customer->password_needs_rehash)->toBeTrue();
    expect(Hash::check('123456', CustomerOtpChallenge::firstOrFail()->code_hash))->toBeTrue();
    expect(CustomerSession::firstOrFail()->token_hash)->toBe(hash('sha256', 'csess_original'));
    $device = Device::byPublicId($this->legacy->ids['device'])->firstOrFail();
    expect($device->publicId())->toBe($this->legacy->ids['device'])->and($device->device_token_hash)->toBe(hash('sha256', 'device-secret'));
    app(TenantContext::class)->run($merchant->id, function () use ($device) {
        $payment = PaymentSession::firstOrFail();
        expect($payment->device_id)->toBe($device->id)->and($payment->amount_paise)->toBe(12345);
        expect($payment->updated_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-17 12:34:56.222');
        expect(PaymentReceivingAccount::firstOrFail()->updated_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-17 12:34:56.222');
        expect($payment->created_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-17 12:34:56.789');
        expect($payment->attributedObservation->id)->toBe($this->legacy->ids['observation']);
        expect(PaymentReceivingAccount::firstOrFail()->current_active_session_id)->toBe($payment->id);
        expect(PaymentObservation::firstOrFail()->merchant_id)->toBe($payment->merchant_id);
        expect(TicketMessage::firstOrFail()->merchant_id)->toBe($payment->merchant_id);
        expect(MerchantBranding::firstOrFail()->app_name)->toBe('Legacy Pay');
        expect(MerchantBranding::firstOrFail()->toArray())->not->toHaveKeys(['gmailConfig', 'refresh_token', 'cloudflareTunnelCommand']);
        expect(MerchantPaymentSettings::firstOrFail()->enabled_payment_methods)->toBe(['UPI', 'CASH_COUNTER', 'CARD_COUNTER']);
        expect(MerchantTestMode::count())->toBe(1);
        $gmail = MerchantGmailConnection::firstOrFail();
        expect($gmail->refresh_token)->toBe('refresh')->and($gmail->legacy_api_key)->toBe('api-key');
        expect($gmail->getRawOriginal('refresh_token'))->not->toBe('refresh');
        expect(AndroidDevice::firstOrFail()->signing_key)->toBe('raw-signing-key');
        expect(WebhookDelivery::firstOrFail()->payload)->toBe('{ "event": "SUCCESS", "amount": "123.45" }');
        expect(AuditLog::firstOrFail()->actor_id)->toBeNull()->and(AuditLog::firstOrFail()->details['legacy_actor_id'])->toBe('ADMIN_USER');
        expect(SecurityEvent::firstOrFail()->raw_message)->toBe('unaltered evidence');
    });
});

it('refuses repeat imports without modifying existing rows', function () {
    $action = app(ImportSqliteAction::class);
    $data = new ImportSqliteData($this->legacy->path);
    $action->handle($data);
    expect(fn () => $action->handle($data))->toThrow(ImportException::class, 'IMPORT_TARGET_NOT_EMPTY');
    expect(Merchant::count())->toBe(1);
    expect(app(TenantContext::class)->isUnscoped())->toBeFalse();
});

it('rolls back every row on invalid legacy data', function (string $sql) {
    $this->legacy->db->exec($sql);
    expect(fn () => app(ImportSqliteAction::class)->handle(new ImportSqliteData($this->legacy->path)))->toThrow(ImportException::class);
    expect(Merchant::count())->toBe(0)->and(Customer::count())->toBe(0);
    expect(app(TenantContext::class)->isUnscoped())->toBeFalse();
})->with([
    "UPDATE payment_sessions SET receiving_account_id='missing'",
    "UPDATE payment_sessions SET status='INVALID'",
    "UPDATE merchants SET branding='not-json'",
    "UPDATE merchants SET branding='42'",
]);

it('rejects missing or unrelated source files', function () {
    expect(fn () => app(ImportSqliteAction::class)->handle(new ImportSqliteData('/no/such/source.sqlite')))->toThrow(ImportException::class, 'IMPORT_SOURCE_INVALID');
    $this->legacy->db->exec('DROP TABLE payment_sessions');
    expect(fn () => app(ImportSqliteAction::class)->handle(new ImportSqliteData($this->legacy->path)))->toThrow(ImportException::class, 'IMPORT_SOURCE_INVALID');
});

it('imports optional settings absent and online COD settings correctly', function (string $branding) {
    $this->legacy->db->prepare('UPDATE merchants SET branding=?')->execute([$branding]);
    $this->legacy->db->exec('UPDATE customers SET password_hash=NULL, otp_code=NULL');
    $action = app(ImportSqliteAction::class);
    $action->handle(new ImportSqliteData($this->legacy->path));
    expect(Customer::firstOrFail()->password_needs_rehash)->toBeFalse()->and(CustomerOtpChallenge::count())->toBe(0);
    app(TenantContext::class)->run($this->legacy->ids['merchant'], function () use ($branding) {
        expect(MerchantPaymentSettings::firstOrFail()->enabled_payment_methods)->toBe(str_contains($branding, 'COD') ? ['UPI', 'COD'] : ['UPI']);
        expect(MerchantTestMode::count())->toBe(0);
    });
})->with(['{}', '{"sellingMode":"ONLINE","enabledPaymentMethods":["UPI","COD"]}', '{"gmailConfig":{}}']);

it('exposes the import command with a count report', function () {
    $this->artisan('paykr:import-sqlite', ['path' => $this->legacy->path])->assertSuccessful();
});

it('keeps UUID device IDs stable and parses dates in UTC', function () {
    $device = Device::factory()->create();
    expect($device->publicId())->toBe($device->id);
    expect(app(LegacyRowMapper::class)->deviceId($device->id))->toBe($device->id);
    expect(LegacyRowMapper::date('2026-10-18T05:30:00+05:30'))->toBe('2026-10-18 00:00:00.000');
});

it('rolls back the import when a model write silently refuses a source row', function () {
    $dispatcher = Merchant::getEventDispatcher();
    Merchant::setEventDispatcher(clone $dispatcher);
    // A saving listener can veto a row without an SQL exception; the final count must detect it.
    WebhookDelivery::saving(fn () => false);
    try {
        expect(fn () => app(ImportSqliteAction::class)->handle(new ImportSqliteData($this->legacy->path)))->toThrow(ImportException::class, 'IMPORT_FAILED');
        expect(Merchant::count())->toBe(0);
    } finally {
        Merchant::setEventDispatcher($dispatcher);
    }
});
