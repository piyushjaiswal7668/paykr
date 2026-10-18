<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;

final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/D', $value)) {
            throw new ValidationException(ErrorCode::INVALID_EMAIL);
        }
        $this->value = $value;
    }

    public static function forCustomer(?string $email, MobileNumber $mobile): self
    {
        return new self(trim($email ?? '') !== '' ? $email : $mobile->value.'@customer.paykr.local');
    }
}
