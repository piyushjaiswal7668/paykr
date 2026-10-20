<?php

declare(strict_types=1);

use App\Domain\Shared\Services\TenantContext;
use App\Filament\Admin\Widgets\PlatformStats;
use App\Filament\Auth\EditProfile;
use App\Filament\Merchant\Pages\LiveMonitor;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\UserTimezone;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('saves a validated display timezone through each panel profile', function (string $panel, string $guard): void {
    $user = User::factory()->create(['is_platform_admin' => true]);
    $other = User::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $this->actingAs($user, $guard);

    Livewire::test(EditProfile::class)
        ->fillForm(['timezone' => 'Asia/Kolkata'])
        ->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->timezone)->toBe('Asia/Kolkata')
        ->and($other->fresh()->timezone)->toBe('UTC');

    Livewire::test(EditProfile::class)
        ->fillForm(['timezone' => 'Not/A_Timezone'])
        ->call('save')->assertHasFormErrors(['timezone']);
    expect($user->fresh()->timezone)->toBe('Asia/Kolkata');
})->with([['admin', 'admin'], ['merchant', 'web']]);

it('converts display dates without modifying stored dates or leaking preferences between users', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(UserTimezone::current())->toBe('UTC')->and(UserTimezone::format(null))->toBe('Never');
    $date = CarbonImmutable::parse('2026-01-15 20:00:00', 'UTC')->toMutable();
    $india = User::factory()->create(['timezone' => 'Asia/Kolkata']);
    $america = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($india, 'admin');
    expect(FilamentTimezone::get())->toBe('Asia/Kolkata')
        ->and(UserTimezone::format($date))->toBe('2026-01-16 01:30:00 IST');
    $this->actingAs($america, 'admin');
    expect(FilamentTimezone::get())->toBe('America/New_York')
        ->and(UserTimezone::format($date))->toBe('2026-01-15 15:00:00 EST')
        ->and(UserTimezone::format(CarbonImmutable::parse('2026-07-15 20:00:00', 'UTC')))->toBe('2026-07-15 16:00:00 EDT')
        ->and($date->format('Y-m-d H:i:s e'))->toBe('2026-01-15 20:00:00 UTC')
        ->and(config('app.timezone'))->toBe('UTC');
    $america->timezone = null;
    expect(UserTimezone::current())->toBe('UTC');
});

it('uses local midnight for today and respects daylight saving day length', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']), 'admin');
    $this->travelTo(CarbonImmutable::parse('2026-03-08 16:00:00', 'UTC'));
    expect(UserTimezone::today()->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-08 05:00:00')
        ->and(UserTimezone::today()->addDay()->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-09 04:00:00');
    $this->travelBack();
});

it('renders native table timestamps and evidence in the selected timezone', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Kolkata']), 'admin');
    $payment = PaymentSession::factory()->make(['created_at' => '2026-01-15 20:00:00']);
    $column = TextColumn::make('created_at')->dateTime('Y-m-d H:i:s')->record($payment);
    expect($column->formatState($payment->created_at))->toBe('2026-01-16 01:30:00');
    $event = (new SecurityEvent)->forceFill(['event_type' => 'TEST', 'created_at' => '2026-01-15 20:00:00']);
    expect(view('filament.admin.security-evidence', ['event' => $event])->render())->toContain('2026-01-16 01:30:00 IST');
});

it('counts payments in the viewer local day on both dashboards', function (): void {
    Bus::fake();
    $this->travelTo(CarbonImmutable::parse('2026-01-16 01:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'Asia/Kolkata', 'is_platform_admin' => true]);
    $merchant = Merchant::factory()->create();
    $merchant->users()->attach($user, ['role' => 'OWNER']);
    foreach (['2026-01-15 18:29:59', '2026-01-15 18:30:00', '2026-01-16 18:29:59', '2026-01-16 18:30:00'] as $timestamp) {
        app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->create(['merchant_id' => $merchant->id, 'status' => 'SUCCESS', 'created_at' => $timestamp, 'amount_paise' => 100]));
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($user, 'admin');
    Livewire::test(PlatformStats::class)->assertSee('₹2.00');
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    $this->actingAs($user, 'web');
    Filament::setTenant($merchant);
    app(TenantContext::class)->run($merchant->id, function (): void {
        expect((new LiveMonitor)->stats()["Today's volume"])->toBe('₹2.00');
    });
    $this->travelBack();
});
