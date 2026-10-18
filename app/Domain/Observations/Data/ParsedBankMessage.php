<?php

declare(strict_types=1);

namespace App\Domain\Observations\Data;

use Carbon\CarbonImmutable;

final readonly class ParsedBankMessage
{
    public function __construct(
        public int $amountPaise,
        public string $amount,
        public ?string $bankReferenceNumber,
        public ?string $senderVpa,
        public CarbonImmutable $observedAt,
        public string $rawText,
    ) {}
}
