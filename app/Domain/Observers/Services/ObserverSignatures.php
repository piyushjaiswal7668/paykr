<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Illuminate\Support\Facades\DB;

final class ObserverSignatures
{
    public function verify(string $identity, #[\SensitiveParameter] string $key, string $body, string $timestamp, string $signature): string
    {
        if ($timestamp === '' || $signature === '') {
            throw new ValidationException(ErrorCode::SIGNATURE_MISSING);
        }
        if (! preg_match('/^[0-9]{13}$/D', $timestamp) || abs(now()->getTimestampMs() - (int) $timestamp) > 60000) {
            throw new ValidationException(ErrorCode::TIMESTAMP_DRIFT);
        }
        if ($key === '' || ! preg_match('/^[a-fA-F0-9]{64}$/D', $signature) || ! hash_equals(hash_hmac('sha256', $body.':'.$timestamp, $key), strtolower($signature))) {
            throw new ValidationException(ErrorCode::SIGNATURE_INVALID);
        }

        return hash('sha256', $identity.':'.$timestamp.':'.strtolower($signature));
    }

    public function consume(string $hash): void
    {
        if (DB::table('observer_request_receipts')->insertOrIgnore(['request_hash' => $hash, 'expires_at' => now()->addMinutes(3)]) !== 1) {
            throw new ValidationException(ErrorCode::SIGNATURE_INVALID);
        }
    }
}
