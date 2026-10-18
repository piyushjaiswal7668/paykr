<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Illuminate\Support\Facades\Hash;

final class PasswordService
{
    public function hash(string $password): string
    {
        if (mb_strlen($password) < 6) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }

        return Hash::make($password);
    }

    public function verify(string $password, string $storedHash): bool
    {
        if (! str_contains($storedHash, ':')) {
            return password_verify($password, $storedHash);
        }
        // Node uses the salt's hexadecimal TEXT as the PBKDF2 salt, not decoded bytes.
        if (! preg_match('/^([1-9][0-9]{0,6}):([a-f0-9]{32}):([a-f0-9]{128})$/D', $storedHash, $parts)) {
            return false;
        }
        $iterations = (int) $parts[1];
        if ($iterations < 1 || $iterations > 1000000) {
            return false;
        }

        return hash_equals($parts[3], hash_pbkdf2('sha512', $password, $parts[2], $iterations, 128));
    }

    public function needsRehash(string $storedHash): bool
    {
        return str_contains($storedHash, ':') || Hash::needsRehash($storedHash);
    }
}
