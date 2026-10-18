<?php

declare(strict_types=1);

use App\Support\ProductionSecrets;

beforeEach(function () {
    config(['app.url' => 'https://pay.example.com', 'app.debug' => false, 'paykr.allow_legacy_merchant_header' => false, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
});

it('rejects empty and public production credentials', function (string $key, mixed $value) {
    $this->app->instance('env', 'production');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'paykr.internal_token' => bin2hex(random_bytes(32)), 'paykr.admin.password' => 'unique-long-admin-password']);
    config([$key => $value]);
    expect(fn () => ProductionSecrets::assertSafe())->toThrow(RuntimeException::class);
})->with([
    ['app.key', ''], ['app.key', str_repeat('a', 33)], ['paykr.internal_token', ''],
    ['paykr.internal_token', 'super_secret_jwt_key_change_in_production_min_32_chars'],
    ['paykr.admin.password', 'AdminSecurePassword123!'], ['paykr.admin.password', 'short'],
    ['paykr.admin.password', ''], ['app.key', 'base64:'.str_repeat('!', 44)],
]);

it('accepts unique production credentials', function () {
    $this->app->instance('env', 'production');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'paykr.internal_token' => bin2hex(random_bytes(32)), 'paykr.admin.password' => bin2hex(random_bytes(16))]);
    ProductionSecrets::assertSafe();
    expect(true)->toBeTrue();
});

it('refuses unsafe production runtime configuration', function (string $key, mixed $value) {
    $this->app->instance('env', 'production');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'paykr.internal_token' => bin2hex(random_bytes(32)), 'paykr.admin.password' => bin2hex(random_bytes(16)), $key => $value]);
    expect(fn () => ProductionSecrets::assertSafe())->toThrow(RuntimeException::class);
})->with([
    ['app.url', 'http://pay.example.com'], ['app.url', 'https://localhost'], ['app.url', ''],
    ['app.debug', true], ['paykr.allow_legacy_merchant_header', true], ['session.secure', false],
    ['session.http_only', false], ['session.same_site', 'none'],
]);
