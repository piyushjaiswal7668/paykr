<?php

declare(strict_types=1);

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\MerchantOperations;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Filament\Merchant\Pages\AndroidSms;
use App\Filament\Merchant\Pages\BrandIdentity;
use App\Filament\Merchant\Pages\CounterDesk;
use App\Filament\Merchant\Pages\CreditReviews;
use App\Filament\Merchant\Pages\GmailConnection;
use App\Filament\Merchant\Pages\LiveMonitor;
use App\Filament\Merchant\Pages\Observers;
use App\Filament\Merchant\Pages\PaymentMethods;
use App\Filament\Merchant\Pages\TeamMembers;
use App\Filament\Merchant\Pages\Transactions;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use App\Models\MerchantPaymentSettings;
use App\Models\ObserverPairingToken;
use App\Models\PaymentObservation;
use App\Models\PaymentSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(function () {
    Bus::fake();
    $this->merchant = Merchant::factory()->create();
    $this->user = User::factory()->create();
    $this->merchant->users()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
});

it('renders the merchant workflow pages', function (string $page) {
    $this->get('/merchant/'.$this->merchant->slug.'/'.$page)->assertOk();
})->with(['live-monitor', 'counter-desk', 'transactions', 'receiving-accounts', 'support-tickets', 'customers', 'brand-identity', 'payment-methods', 'android-sms', 'gmail-connection', 'observers', 'webhooks', 'api-keys', 'payment-simulator', 'team-members', 'training']);

it('denies a cashier access to settings and checks permissions again at the service boundary', function () {
    $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => 'cashier']);
    foreach (['brand-identity', 'payment-methods', 'receiving-accounts', 'customers', 'support-tickets'] as $path) {
        $this->get('/merchant/'.$this->merchant->slug.'/'.$path)->assertForbidden();
    }
    expect(fn () => app(MerchantOperations::class)->execute(new PanelCommand($this->user->id, $this->merchant->id, 'clear-queue')))->toThrow(ValidationException::class);
});

it('confirms a counter payment through Livewire with an actor and rejects replay', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $payment = PaymentSession::factory()->active()->create(['method' => 'CASH_COUNTER']);
        Livewire::test(CounterDesk::class)->callTableAction('confirm', $payment)->assertHasNoTableActionErrors();
        expect($payment->refresh()->status->value)->toBe('SUCCESS');
        expect(AuditLog::where('action', 'COUNTER_MANUAL_PAYMENT_CONFIRMED')->sole()->actor_id)->toBe($this->user->id);
        expect(fn () => app(MerchantOperations::class)->execute(new PanelCommand($this->user->id, $this->merchant->id, 'counter-confirm', $payment->id)))->toThrow(ValidationException::class);
    });
});

it('enforces the complete merchant page role matrix', function (string $role) {
    $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => $role]);
    $matrix = ['live-monitor' => ['owner', 'manager', 'cashier', 'developer'], 'transactions' => ['owner', 'manager', 'cashier', 'developer'], 'observers' => ['owner', 'manager', 'cashier', 'developer'], 'counter-desk' => ['owner', 'manager', 'cashier'], 'brand-identity' => ['owner', 'manager'], 'receiving-accounts' => ['owner', 'manager'], 'payment-methods' => ['owner', 'manager'], 'webhooks' => ['owner', 'developer'], 'api-keys' => ['owner', 'developer'], 'payment-simulator' => ['owner', 'developer'], 'android-sms' => ['owner', 'manager', 'developer'], 'gmail-connection' => ['owner', 'manager', 'developer'], 'customers' => ['owner', 'manager'], 'support-tickets' => ['owner', 'manager'], 'team-members' => ['owner']];
    foreach ($matrix as $page => $roles) {
        $this->get('/merchant/'.$this->merchant->slug.'/'.$page)->assertStatus(in_array($role, $roles, true) ? 200 : 403);
    }
})->with(['owner', 'manager', 'cashier', 'developer']);

it('does not expose another merchants records through tables or forged actions', function () {
    $other = Merchant::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => PaymentSession::factory()->active()->create(['method' => 'CASH_COUNTER', 'order_id' => 'FOREIGN-PRIVATE-ORDER']));
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () use ($foreign) {
        Livewire::test(CounterDesk::class)->assertCanNotSeeTableRecords([$foreign]);
        expect(fn () => app(MerchantOperations::class)->execute(new PanelCommand($this->user->id, $this->merchant->id, 'counter-confirm', $foreign->id)))->toThrow(ModelNotFoundException::class);
    });
    $this->get('/merchant/'.$other->slug.'/transactions')->assertNotFound();
    $this->get('/merchant/'.$this->merchant->slug.'/transactions')->assertDontSee('FOREIGN-PRIVATE-ORDER');
});

it('refreshes consumed Android enrollment QR codes and preserves parse-only behavior', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $component = Livewire::test(AndroidSms::class)->callAction('pair');
        $id = $component->get('result')['sessionId'];
        ObserverPairingToken::findOrFail($id)->update(['consumed_at' => now()]);
        $component->call('refreshPairing');
        expect($component->get('result')['sessionId'])->not->toBe($id);
        $component->callAction('parse', ['text' => 'INR 10 credited UTR 123456789012'])->assertSet('result.attributed', false);
        expect(PaymentObservation::count())->toBe(0);
    });
});

it('rechecks page access after a role is revoked', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $component = Livewire::test(BrandIdentity::class);
        $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => 'cashier']);
        $component->call('$refresh')->assertForbidden();
    });
});

it('saves the complete branding form with its optional empty fields', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        Livewire::test(BrandIdentity::class)->fillForm(['app_name' => 'Saved Brand'])->call('save')->assertHasNoErrors()->assertNotified('Brand identity saved');
        expect(MerchantBranding::sole()->app_name)->toBe('Saved Brand');
    });
});

it('stores an uploaded logo on the public disk and persists its URL', function () {
    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        Livewire::test(BrandIdentity::class)
            ->fillForm(['uploaded_logo' => UploadedFile::fake()->image('store.png')])
            ->call('save')->assertHasNoErrors();
        $url = MerchantBranding::sole()->logo_url;
        expect($url)->toContain('/storage/brand-logos/');
        Storage::disk('public')->assertExists('brand-logos/'.basename($url));
    });
});

it('saves payment methods and returns usable team invitation links', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function () {
        Livewire::test(PaymentMethods::class)
            ->fillForm(['selling_mode' => 'ONLINE', 'cod_enabled' => true, 'cash_enabled' => false, 'card_enabled' => false])
            ->call('save')->assertHasNoErrors()->assertNotified('Payment methods saved');
        expect(MerchantPaymentSettings::sole()->cod_enabled)->toBeTrue();
        $component = Livewire::test(TeamMembers::class)
            ->callTableAction('invite', data: ['name' => 'New Member', 'email' => 'new@example.com', 'role' => 'manager']);
        expect($component->get('result')['invitationUrl'])->toContain('/merchant/password-reset/reset?');
        expect($component->get('result'))->not->toHaveKey('invitationToken');
        $component->callTableAction('invite', data: ['name' => 'New Member', 'email' => 'new@example.com', 'role' => 'manager']);
        expect($this->merchant->users()->count())->toBe(2);
    });
});

it('accepts live refresh events on merchant operational pages', function (string $page) {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, fn () => Livewire::test($page)->dispatch('paykr-live-update')->assertSuccessful());
})->with([
    CounterDesk::class,
    CreditReviews::class,
    LiveMonitor::class,
    Observers::class,
    Transactions::class,
]);

it('handles empty Android pairing refreshes and starts Gmail authorization', function () {
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    config(['paykr.gmail.client_id' => 'test-client', 'paykr.gmail.client_secret' => 'test-secret']);
    app(TenantContext::class)->run($this->merchant->id, function () {
        Livewire::test(AndroidSms::class)->call('refreshPairing')->assertSet('result', []);
        Livewire::test(GmailConnection::class)->callAction('connect')->assertRedirect();
    });
});
