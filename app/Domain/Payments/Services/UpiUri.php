<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Vpa;

final class UpiUri
{
    public static function build(string $vpa, string $payeeName, Money $amount, string $transactionRef = '', string $transactionNote = ''): string
    {
        if ($amount->paise === 0) {
            throw new ValidationException(ErrorCode::INVALID_AMOUNT);
        }
        $params = ['pa' => (new Vpa(trim($vpa)))->value, 'pn' => trim($payeeName), 'am' => $amount->toRupeesString()];
        $note = trim($transactionRef !== '' ? $transactionRef : $transactionNote);
        if ($note !== '') {
            $params['tn'] = $note;
        }

        // WHATWG URLSearchParams leaves '*' unescaped, unlike PHP's form encoder.
        return 'upi://pay?'.str_replace('%2A', '*', http_build_query($params, '', '&', PHP_QUERY_RFC1738));
    }

    public static function intent(string $packageName, string $vpa, string $payeeName, Money $amount, string $transactionRef = '', string $transactionNote = ''): string
    {
        $packageName = trim($packageName);
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+$/D', $packageName)) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }

        return 'intent://pay?'.substr(self::build($vpa, $payeeName, $amount, $transactionRef, $transactionNote), 10).'#Intent;scheme=upi;package='.$packageName.';end';
    }
}
