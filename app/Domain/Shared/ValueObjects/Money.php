<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;

final readonly class Money
{
    public function __construct(public int $paise)
    {
        if ($paise < 0) {
            throw new ValidationException(ErrorCode::INVALID_AMOUNT);
        }
    }

    public static function fromRupees(string|int|float $rupees): self
    {
        return new self(self::toPaise($rupees));
    }

    public static function toPaise(string|int|float $rupees): int
    {
        $value = trim((string) $rupees);
        if (! preg_match('/^\d+(\.\d{1,2})?$/D', $value)) {
            throw new ValidationException(ErrorCode::INVALID_AMOUNT);
        }
        $parts = explode('.', $value);
        $digits = ltrim($parts[0].str_pad($parts[1] ?? '', 2, '0'), '0');
        $max = (string) PHP_INT_MAX;
        if ($digits === '' || strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new ValidationException(ErrorCode::INVALID_AMOUNT);
        }

        return (int) $digits;
    }

    public function toRupeesString(): string
    {
        return intdiv($this->paise, 100).'.'.str_pad((string) ($this->paise % 100), 2, '0', STR_PAD_LEFT);
    }

    public function formatINR(): string
    {
        return '₹'.$this->toRupeesString();
    }
}
