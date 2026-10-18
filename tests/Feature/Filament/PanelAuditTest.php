<?php

declare(strict_types=1);

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\PanelAuditContext;
use App\Domain\Management\Services\StoreSettings;
use App\Domain\Shared\Services\TenantContext;
use App\Http\Middleware\PanelAudit;
use App\Http\Middleware\SetMerchantContext;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('attributes support-session mutations to the real administrator and restores audit context', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $merchant = Merchant::factory()->create();
    $merchant->users()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner, 'web')->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($merchant);
    $request = Request::create('/merchant/support', 'POST', server: ['REMOTE_ADDR' => '192.0.2.80']);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(['paykr_impersonator' => $admin->id, 'paykr_impersonation_expires' => now()->addMinutes(15)->timestamp]);
    app(PanelAudit::class)->handle($request, function () use ($owner, $merchant) {
        app(StoreSettings::class)->save(new PanelCommand($owner->id, $merchant->id, 'branding', data: ['app_name' => 'Support change', 'primary_color' => '#2563eb', 'header_theme' => 'solid'], ip: '192.0.2.1'));

        return response('saved');
    });
    $audit = app(TenantContext::class)->run($merchant->id, fn () => AuditLog::where('action', 'STORE_BRANDING_UPDATED')->sole());
    expect($audit->actor_id)->toBe($admin->id)->and($audit->ip_address)->toBe('192.0.2.80')
        ->and($audit->details['impersonatedUserId'])->toBe($owner->id)
        ->and(app(PanelAuditContext::class)->actor)->toBeNull();
    $this->post('/admin/end-impersonation')->assertRedirect('/admin/merchants');
    $this->assertGuest('web');
    $this->assertAuthenticatedAs($admin, 'admin');
});

it('retains scoped tenant and audit identity after Livewire persistent middleware returns', function () {
    $owner = User::factory()->create();
    $merchant = Merchant::factory()->create();
    $merchant->users()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($merchant);
    request()->headers->set('X-Livewire', 'true');
    $request = Request::create('/merchant/'.$merchant->slug.'/counter-desk', 'GET', server: ['REMOTE_ADDR' => '192.0.2.81']);
    $request->setLaravelSession(app('session.store'));
    $payment = app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->active()->create(['method' => 'CASH_COUNTER']));
    app(SetMerchantContext::class)->handle($request, fn () => response(''));
    app(PanelAudit::class)->handle($request, fn () => response(''));
    // Livewire invokes the component only after its replayed middleware has returned.
    expect(PaymentSession::find($payment->id)?->id)->toBe($payment->id);
    app(StoreSettings::class)->save(new PanelCommand($owner->id, $merchant->id, 'branding', data: ['app_name' => 'Livewire update', 'primary_color' => '#2563eb', 'header_theme' => 'solid']));
    expect(AuditLog::where('action', 'STORE_BRANDING_UPDATED')->sole()->ip_address)->toBe('192.0.2.81');
});
