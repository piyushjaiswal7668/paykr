<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ProductionSecrets
{
    public static function assertSafe(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        $url = parse_url((string) config('app.url'));
        if (filter_var(config('app.url'), FILTER_VALIDATE_URL) === false || ! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true) || isset($url['user']) || isset($url['pass'])) {
            throw new RuntimeException('Set a public HTTPS APP_URL before running in production.');
        }
        if (config('app.debug') || config('paykr.allow_legacy_merchant_header') || ! config('session.secure') || ! config('session.http_only') || config('session.same_site') !== 'lax') {
            throw new RuntimeException('Production requires debug and legacy merchant authentication disabled, and Secure HttpOnly SameSite=Lax cookies.');
        }
        $values = ['APP_KEY' => config('app.key'), 'PAYKR_INTERNAL_TOKEN' => config('paykr.internal_token'), 'PAYKR_ADMIN_PASSWORD' => config('paykr.admin.password')];
        foreach ($values as $name => $value) {
            $minimum = $name === 'PAYKR_ADMIN_PASSWORD' ? 12 : 32;
            if (! is_string($value) || strlen(trim($value)) < $minimum || in_array($value, ['super_secret_jwt_key_change_in_production_min_32_chars', 'AdminSecurePassword123!', 'paykr-dev-only-insecure-admin-secret'], true) || preg_match('/change[_ -]?me|your[_ -].*here|example|placeholder/i', $value)) {
                throw new RuntimeException("Set a unique {$name} before running in production.");
            }
        }
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Generate a valid APP_KEY before running in production.');
        }
    }
}
