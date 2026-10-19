<?php

declare(strict_types=1);

use App\Domain\Shared\Errors\ValidationException;
use App\Filament\Admin\Pages\Merchants;
use App\Filament\Admin\Pages\PlatformSettings;
use App\Filament\Admin\Widgets\ObserverHealth;
use App\Filament\Admin\Widgets\PlatformStats;
use App\Http\Resources\V1\ApkResource;
use App\Models\Merchant;
use App\Models\PlatformOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('renders platform pages only for enrolled admins', function (string $path) {
    Http::fake(['*' => Http::response(['status' => 'DISCONNECTED'])]);
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->actingAs($admin, 'admin')->get('/admin'.$path)->assertOk();
    $other = User::factory()->create();
    $this->actingAs($other, 'admin')->get('/admin'.$path)->assertForbidden();
})->with(['', '/merchants', '/customers', '/payments', '/webhook-deliveries', '/audit-history', '/security', '/system', '/whats-app-sender', '/platform-settings', '/api-keys', '/training']);

it('saves platform defaults and displays admin health widgets', function () {
    Http::fake(['*' => Http::response(['status' => 'DISCONNECTED'])]);
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(PlatformSettings::class)
        ->fillForm(['app_name' => 'Test Platform', 'primary_color' => '#123456', 'otp_max_attempts' => 5, 'otp_resend_cooldown_seconds' => 30, 'otp_sends_per_mobile_10_minutes' => 3, 'otp_sends_per_ip_hour' => 10])
        ->call('save')->assertHasNoErrors()->assertNotified('Platform defaults saved');
    expect(PlatformOption::findOrFail('defaults')->value['app_name'])->toBe('Test Platform');
    Livewire::test(ObserverHealth::class)->assertSuccessful();
    Livewire::test(PlatformStats::class)->assertSuccessful();
});

it('creates merchant invitation links and audits a temporary owner support session', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $data = ['business_name' => 'Invited Shop', 'slug' => 'invited-shop', 'contact_email' => 'shop@example.com', 'owner_name' => 'Shop Owner', 'owner_email' => 'owner@example.com'];
    $component = Livewire::test(Merchants::class)->callTableAction('create', data: $data);
    expect($component->get('result')['invitationUrl'])->toContain('/merchant/password-reset/reset?');
    expect($component->get('result'))->not->toHaveKey('invitationToken');
    $component->callTableAction('create', data: $data);
    expect(Merchant::count())->toBe(1);
    $merchant = Merchant::sole();
    Livewire::listen('hydrate', fn () => request()->setLaravelSession(app('session.store')));
    $component->callTableAction('impersonate', $merchant)->assertRedirect('/merchant/invited-shop/live-monitor');
    expect(session('paykr_impersonator'))->toBe($admin->id);
    expect(auth('web')->user()->email)->toBe('owner@example.com');
});

it('publishes an uploaded APK and serves it as an attachment', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $original = storage_path();
    $temporary = sys_get_temp_dir().'/paykr-apk-panel-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($temporary.'/app');
    app()->useStoragePath($temporary);
    config(['filesystems.disks.apk-test' => ['driver' => 'local', 'root' => $temporary.'/app/uploads'], 'livewire.temporary_file_upload.disk' => 'apk-test']);
    $file = $temporary.'/app/source.apk';
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE);
    $zip->addFromString('AndroidManifest.xml', 'fixture');
    $zip->addFromString('classes.dex', 'fixture');
    $zip->close();
    // Keep Livewire upload files inside the storage root accepted by the publisher.
    try {
        $component = Livewire::test(PlatformSettings::class);
        Storage::disk('apk-test')->put('livewire-tmp/observer.apk', file_get_contents($file));
        $upload = new TemporaryUploadedFile('observer.apk', 'apk-test');
        $action = collect($component->instance()->getCachedHeaderActions())->first(fn ($action) => $action->getName() === 'apk');
        ($action->getActionFunction())(['version' => '1.0.0', 'apk' => $upload]);
        $response = ApkResource::response();
        expect($response->headers->get('Content-Type'))->toBe('application/vnd.android.package-archive');
        unlink($temporary.'/app/private/releases/paykr-sms-gateway.apk');
        expect(fn () => ApkResource::response())->toThrow(ValidationException::class, 'NOT_FOUND');
    } finally {
        app()->useStoragePath($original);
        File::deleteDirectory($temporary);
    }
});
