<?php

declare(strict_types=1);

namespace App\Domain\Observations\Services;

use App\Domain\Observations\Data\ParsedBankMessage;
use Carbon\CarbonImmutable;

final class BankEmailParser
{
    public static function parse(string $subject, string $body, ?CarbonImmutable $observedAt = null): ?ParsedBankMessage
    {
        $text = trim($subject.' '.$body);
        if (! preg_match('~(credited|credit|received|deposit|payment received|payment|upi/cr|transferred)~i', $text)
            || (preg_match('/(debited|withdrawn|sent rs|paid rs)/i', $text) && ! preg_match('/(credited|credit|received|deposit)/i', $text))) {
            return null;
        }
        $amount = AmountExtractor::extract($text);
        if ($amount === null) {
            return null;
        }
        $reference = null;
        foreach (['/(?:utr|ref(?:\s*no)?|txn(?:\s*id)?|rrn)[:\s#]+([a-zA-Z0-9\-_]{6,35})/i', '~UPI/CR/([a-zA-Z0-9\-_]+)~i', '/\b([0-9]{12})\b/'] as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $reference = trim($match[1]);
                break;
            }
        }
        preg_match('/(?:from|by|vpa)\s+([a-zA-Z0-9.\-_]{2,100}@[a-zA-Z0-9.\-_]{2,64})/i', $text, $vpa);

        return new ParsedBankMessage($amount['amountPaise'], $amount['amountStr'], $reference, isset($vpa[1]) ? rtrim($vpa[1], '.,;') : null, $observedAt ?? CarbonImmutable::now(), $text);
    }
}
