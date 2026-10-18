<?php

declare(strict_types=1);

namespace App\Domain\Observations\Data;

use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Observations\Enums\ObserverSource;
use Carbon\CarbonImmutable;

final readonly class NormalizedObservation
{
    /** @param array<string, mixed> $rawPayload */
    public function __construct(
        public string $receivingAccountId,
        public ObserverSource $source,
        public int $amountPaise,
        public CarbonImmutable $observedAt,
        public ?string $bankReferenceNumber,
        public ?string $senderVpa,
        public string $fingerprintHash,
        public array $rawPayload,
        public AttributionStatus $attributionStatus = AttributionStatus::UNATTRIBUTED,
    ) {}
}
