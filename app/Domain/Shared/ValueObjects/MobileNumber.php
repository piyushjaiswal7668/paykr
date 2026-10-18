<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;

final readonly class MobileNumber
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = substr(preg_replace('/\D/', '', $value) ?? '', -10);
        if (! preg_match('/^[6-9]\d{9}$/D', $normalized)) {
            throw new ValidationException(ErrorCode::INVALID_MOBILE);
        }
        $this->value = $normalized;
    }
}
