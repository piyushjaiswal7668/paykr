<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['merchant', 'admin'] as $audience) {
        $dir = storage_path('app/private/training/'.$audience);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/99.mp4', '0123456789');
    }
});
afterEach(function () {
    foreach (['merchant', 'admin'] as $audience) {
        @unlink(storage_path('app/private/training/'.$audience.'/99.mp4'));
    }
});
it('requires a signed link and active merchant membership, and denies admin media to merchants', function () {
    $url = URL::temporarySignedRoute('training.video', now()->addMinutes(5), ['audience' => 'merchant', 'id' => '99']);
    $this->get($url)->assertForbidden();
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create();
    $this->actingAs($user)->get($url)->assertForbidden();
    $merchant->users()->attach($user, ['role' => 'cashier']);
    $this->get('/training/media/merchant/99')->assertForbidden();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->withHeader('Range', 'bytes=0-3')->get($url)->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/10');
    $this->get(URL::temporarySignedRoute('training.video', now()->addMinutes(5), ['audience' => 'admin', 'id' => '99']))->assertForbidden();
    $merchant->update(['status' => 'SUSPENDED']);
    $this->get($url)->assertForbidden();
});
it('allows enrolled administrators and rejects expired links and missing files', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $url = URL::temporarySignedRoute('training.video', now()->addMinutes(5), ['audience' => 'admin', 'id' => '99']);
    $this->actingAs($admin, 'admin')->get($url)->assertForbidden();
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->get($url)->assertOk();
    $this->get(URL::temporarySignedRoute('training.video', now()->subMinute(), ['audience' => 'admin', 'id' => '99']))->assertForbidden();
    $this->get(URL::temporarySignedRoute('training.video', now()->addMinutes(5), ['audience' => 'admin', 'id' => '98']))->assertNotFound();
});
