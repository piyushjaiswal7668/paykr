<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantRole;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Filament\Auth\Login;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Models\Merchant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders both login pages and requires login for panels and Horizon', function () {
    $this->get('/admin/login')->assertOk();
    $this->get('/merchant/login')->assertOk();
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/merchant')->assertRedirect('/merchant/login');
    $this->get('/horizon')->assertForbidden();
});

it('requires admin TOTP setup before showing the dashboard', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'admin')->get('/admin')->assertRedirect();
    expect(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeTrue();
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['recovery-code']);
    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
    expect($admin->getRawOriginal('two_factor_secret'))->not->toBe('JBSWY3DPEHPK3PXP');
    expect($admin->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
    expect($admin->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['recovery-code']);
    expect($admin->getAppAuthenticationHolderName())->toBe($admin->email);
    expect($admin->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
});

it('allows each merchant role only into its own tenant', function (MerchantRole $role) {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create();
    $other = Merchant::factory()->create();
    $merchant->users()->attach($user, ['role' => $role->value]);
    expect($user->getTenants(Filament::getPanel('merchant'))->modelKeys())->toBe([$merchant->id]);
    expect($user->canAccessTenant($merchant))->toBeTrue()->and($user->canAccessTenant($other))->toBeFalse();
    $this->actingAs($user)->get('/merchant/'.$merchant->slug)->assertRedirect('/merchant/'.$merchant->slug.'/live-monitor');
    $this->get('/merchant/'.$merchant->slug.'/live-monitor')->assertOk()->assertSee($merchant->business_name);
    $this->actingAs($user)->get('/merchant/'.$other->slug)->assertNotFound();
    $this->actingAs($user, 'admin')->get('/admin')->assertForbidden();
})->with(MerchantRole::cases());

it('denies nonmembers and suspended tenants', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(['status' => MerchantStatus::SUSPENDED]);
    $merchant->users()->attach($user, ['role' => MerchantRole::OWNER->value]);
    expect($user->canAccessTenant($merchant))->toBeFalse()->and($user->getTenants(Filament::getPanel('merchant')))->toBeEmpty();
    $this->actingAs($user)->get('/merchant/'.$merchant->slug)->assertForbidden();
});

it('does not accept a merchant session as an admin session', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'web')->get('/admin')->assertRedirect('/admin/login');
    $this->get('/horizon')->assertForbidden();
});

it('throttles login after five attempts for fifteen minutes', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $login = Livewire::test(Login::class)->fillForm(['email' => 'missing@example.com', 'password' => 'wrong']);
    for ($i = 0; $i < 5; $i++) {
        $login->call('authenticate')->assertHasFormErrors(['email']);
    }
    $login->call('authenticate')->assertNotified();
    $this->travel(16)->minutes();
    $login->call('authenticate')->assertHasFormErrors(['email']);
});

it('seeds an admin and empty demo merchant idempotently without resetting passwords', function () {
    config(['paykr.admin.email' => 'seed@example.com', 'paykr.admin.password' => 'unique-long-test-password']);
    $this->seed();
    $this->seed();
    expect(User::count())->toBe(1)->and(Merchant::count())->toBe(1);
    $user = User::firstOrFail();
    expect($user->is_platform_admin)->toBeTrue()->and($user->merchants()->count())->toBe(1);
    expect(Hash::check('unique-long-test-password', $user->password))->toBeTrue();
    config(['paykr.admin.password' => 'different-long-password']);
    $this->seed();
    expect(Hash::check('unique-long-test-password', $user->fresh()->password))->toBeTrue();
});

it('requires a valid TOTP before authenticating an enrolled admin', function () {
    $admin = User::factory()->create(['is_platform_admin' => true, 'password' => 'valid-password']);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'valid-password'])
        ->call('authenticate')
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));
    $this->assertGuest('admin');
});

it('does not seed with missing credentials', function () {
    config(['paykr.admin.email' => null, 'paykr.admin.password' => null]);
    expect(fn () => $this->seed())->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
});

it('converts domain errors into Filament notifications', function () {
    expect(NotifiesPaykrErrors::run(fn () => 'ok'))->toBe('ok');
    expect(NotifiesPaykrErrors::run(fn () => throw new ValidationException(ErrorCode::INVALID_AMOUNT)))->toBeNull();
    Notification::assertNotified();
});
