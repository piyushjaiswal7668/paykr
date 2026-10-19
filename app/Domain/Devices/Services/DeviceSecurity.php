<?php

declare(strict_types=1);

namespace App\Domain\Devices\Services;

use JsonException;

final class DeviceSecurity
{
    /** @param array<string, mixed> $signals */
    public static function fingerprint(array $signals): string
    {
        $string = static fn (string $key): string => is_scalar($signals[$key] ?? null) ? (string) $signals[$key] : '';
        $screen = $string('screenSignature');
        if ($screen === '' && $string('screenWidth') !== '' && $string('screenHeight') !== '') {
            $screen = $string('screenWidth').'x'.$string('screenHeight').'x'.($string('screenColorDepth') ?: '24');
        }
        $normalized = [
            'canvasSignature' => trim($string('canvasSignature')),
            'deviceMemory' => max(0, (float) $string('deviceMemory')),
            'hardwareConcurrency' => max(0, (int) $string('hardwareConcurrency')),
            'language' => strtolower(trim($string('language'))),
            'platform' => strtolower(trim($string('platform'))),
            'screenSignature' => $screen,
            'timezone' => trim($string('timezone')),
            'userAgent' => preg_replace('/\s+/u', ' ', trim($string('userAgent'))),
            'webglRenderer' => strtolower(trim($string('webglRenderer') ?: $string('webgl'))),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    /** @param string|array<string, mixed> $publicKey */
    public static function verifySignature(string|array $publicKey, string $payload, string $signatureBase64): bool
    {
        $pem = self::canonicalPublicKey($publicKey);
        if ($pem === null) {
            return false;
        }
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            return false;
        }
        if (strlen($signature) === 64) {
            $integers = '';
            foreach (str_split($signature, 32) as $part) {
                $part = ltrim($part, "\0");
                if ($part === '' || ord($part[0]) >= 128) {
                    $part = "\0".$part;
                }
                $integers .= "\x02".chr(strlen($part)).$part;
            }
            $der = "\x30".chr(strlen($integers)).$integers;
            if (@openssl_verify($payload, $der, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return @openssl_verify($payload, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param string|array<string, mixed> $publicKey */
    public static function canonicalPublicKey(string|array $publicKey): ?string
    {
        try {
            if (is_string($publicKey) && str_starts_with(trim($publicKey), '{')) {
                $publicKey = json_decode($publicKey, true, 512, JSON_THROW_ON_ERROR);
            }
            if (is_array($publicKey)) {
                if (($publicKey['kty'] ?? null) !== 'EC' || ($publicKey['crv'] ?? null) !== 'P-256' || ! is_string($publicKey['x'] ?? null) || ! is_string($publicKey['y'] ?? null)) {
                    return null;
                }
                $x = base64_decode(strtr($publicKey['x'], '-_', '+/'), true);
                $y = base64_decode(strtr($publicKey['y'], '-_', '+/'), true);
                if ($x === false || $y === false || strlen($x) !== 32 || strlen($y) !== 32) {
                    return null;
                }
                // Fixed SubjectPublicKeyInfo prefix for id-ecPublicKey / prime256v1.
                $publicKey = base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004').$x.$y);
            }
            if (! str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
                $publicKey = "-----BEGIN PUBLIC KEY-----\n".chunk_split(trim($publicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
            }
            $key = @openssl_pkey_get_public($publicKey);
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if ($details === false || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
                return null;
            }

            return $details['key'];
        } catch (JsonException) {
            return null;
        }
    }
}
