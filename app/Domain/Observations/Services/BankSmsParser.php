<?php

declare(strict_types=1);

namespace App\Domain\Observations\Services;

use App\Domain\Observations\Data\ParsedBankMessage;
use Carbon\CarbonImmutable;

final class BankSmsParser
{
    public static function parse(string $text, ?CarbonImmutable $observedAt = null): ?ParsedBankMessage
    {
        $text = trim($text);
        if (! preg_match('~(credited|received|deposit|payment received|payment|upi/cr|transferred)~i', $text)
            || (preg_match('/(debited|withdrawn|sent rs|paid rs)/i', $text) && ! preg_match('/(credited|received|deposit)/i', $text))) {
            return null;
        }
        $amount = AmountExtractor::extract($text);
        if ($amount === null) {
            return null;
        }
        $reference = null;
        foreach ([
            '/(?:upi\s*ref(?:erence)?\s*(?:no\.?|num)?|utr(?:\s*no\.?)?|rrn(?:\s*no\.?)?|txn\s*(?:id|ref)?|ref\s*no\.?)[\s:=-]+([a-zA-Z0-9]{6,25})/i',
            '/(?:ref(?:\s*no)?\s*[:#\s]?\s*)([a-zA-Z0-9]{8,22})/i',
            '/\b([0-9]{12})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $reference = trim($match[1]);
                break;
            }
        }
        preg_match('/(?:from|by|vpa)\s+([a-zA-Z0-9.\-_]{2,64}@[a-zA-Z0-9.\-_]{2,32})/i', $text, $vpa);

        return new ParsedBankMessage($amount['amountPaise'], $amount['amountStr'], $reference, isset($vpa[1]) ? rtrim($vpa[1], '.,;') : null, $observedAt ?? CarbonImmutable::now(), $text);
    }
}
