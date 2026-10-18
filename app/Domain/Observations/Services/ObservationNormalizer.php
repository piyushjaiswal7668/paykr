<?php

declare(strict_types=1);

namespace App\Domain\Observations\Services;

use App\Domain\Observations\Data\NormalizedObservation;
use App\Domain\Observations\Data\ObservationData;
use App\Domain\Observations\Enums\ObserverSource;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final class ObservationNormalizer
{
    public static function normalize(ObservationData $input): NormalizedObservation
    {
        if (trim($input->receivingAccountId) === '') {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $payload = $input->rawPayload;
        $text = $payload['text'] ?? $payload['body'] ?? $payload['notificationText'] ?? $payload['snippet'] ?? '';
        if (! is_string($text)) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $paise = $input->amountPaise ?? ($input->amount !== null ? Money::toPaise($input->amount) : (AmountExtractor::extract($text)['amountPaise'] ?? null));
        if ($paise === null || $paise <= 0) {
            throw new ValidationException(ErrorCode::INVALID_AMOUNT);
        }
        $observedAt = $input->observedAt ?? self::timestamp($payload['timestamp'] ?? $payload['postTime'] ?? $payload['date'] ?? null);
        preg_match('/(?:utr|ref|txn|rrn|reference)[:\s#]+([a-zA-Z0-9\-_]{6,35})/i', $text, $reference);
        preg_match('/(?:from|by)\s+([a-zA-Z0-9.\-_]{2,100}@[a-zA-Z0-9.\-_]{2,64})/i', $text, $vpa);
        $bankReference = $input->bankReferenceNumber ?: ($reference[1] ?? null);
        $senderVpa = $input->senderVpa ?: (isset($vpa[1]) ? rtrim($vpa[1], '.,;') : null);

        return new NormalizedObservation($input->receivingAccountId, $input->source, $paise, $observedAt, $bankReference, $senderVpa, self::fingerprint($input->receivingAccountId, $paise, $bankReference, $observedAt, $input->source), $payload);
    }

    public static function fingerprint(string $accountId, int $paise, ?string $reference, CarbonImmutable $observedAt, ?ObserverSource $source = null): string
    {
        $reference = trim($reference ?? '');
        $entropy = $reference !== '' ? 'REF:'.strtoupper($reference) : 'TIME_BUCKET:'.(int) floor($observedAt->getTimestamp() / 60).':'.($source->value ?? '');

        return hash('sha256', $accountId.':'.$paise.':'.$entropy);
    }

    private static function timestamp(mixed $value): CarbonImmutable
    {
        try {
            if (is_int($value)) {
                return CarbonImmutable::createFromTimestampMs($value, 'UTC');
            }
            if (is_string($value) && $value !== '') {
                return CarbonImmutable::parse($value, 'UTC');
            }
        } catch (InvalidFormatException) {
            // Preserve Node's fallback for invalid source timestamps.
        }

        return CarbonImmutable::now();
    }
}
