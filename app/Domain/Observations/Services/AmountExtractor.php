<?php

declare(strict_types=1);

namespace App\Domain\Observations\Services;

use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Money;

final class AmountExtractor
{
    /** @return array{amountStr: string, amountPaise: int}|null */
    public static function extract(string $text): ?array
    {
        // Ordered expressions ported from core/src/bank-sms-parser.js.
        $patterns = [
            '/(?:\b(?:rs|inr)\.?|₹)\s*[:=-]?\s*([\d,]+(?:\.\d{1,2})?)/i',
            '/(?:^|[^\w.])([\d,]+(?:\.\d{1,2})?)[\s:=-]*(?:(?:rs\.?|inr)\b|₹)/i',
            '/(?:credited|received|deposit|payment|paid|transferred|upi|cr)[\s\w:]*?([\d,]+(?:\.\d{1,2})?)|([\d,]+(?:\.\d{1,2})?)[\s\w:]*?(?:credited|received|deposit|payment|paid|transferred|upi|cr)/i',
            '/\b(\d{1,6}\.\d{2})\b/',
        ];
        foreach ($patterns as $index => $pattern) {
            if (! preg_match($pattern, $text, $match)) {
                continue;
            }
            $clean = str_replace(',', '', $match[1] !== '' ? $match[1] : $match[2]);
            try {
                $paise = Money::toPaise($clean);
                if ($index === 2 && $paise >= 1000000000) {
                    continue;
                }

                return ['amountStr' => $clean, 'amountPaise' => $paise];
            } catch (ValidationException) {
                // Try the next legacy pattern when a captured value is not money.
            }
        }

        return null;
    }
}
