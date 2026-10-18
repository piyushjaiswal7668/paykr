<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;

final readonly class Vpa
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z0-9.\-_]{2,64}$/D', $value)) {
            throw new ValidationException(ErrorCode::INVALID_VPA);
        }
    }
}
