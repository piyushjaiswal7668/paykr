<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
it('renders platform pages only for enrolled admins', function (string $path) {
    Http::fake(['*' => Http::response(['status' => 'DISCONNECTED'])]);
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->actingAs($admin, 'admin')->get('/admin'.$path)->assertOk();
    $other = User::factory()->create();
    $this->actingAs($other, 'admin')->get('/admin'.$path)->assertForbidden();
})->with(['', '/merchants', '/customers', '/payments', '/webhook-deliveries', '/audit-history', '/security', '/system', '/whats-app-sender', '/platform-settings', '/api-keys']);
