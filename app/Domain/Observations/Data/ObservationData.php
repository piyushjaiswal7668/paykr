<?php

declare(strict_types=1);

namespace App\Domain\Observations\Data;

use App\Domain\Observations\Enums\ObserverSource;
use Carbon\CarbonImmutable;

final readonly class ObservationData
{
    /** @param array<string, mixed> $rawPayload */
    public function __construct(
        public string $receivingAccountId,
        public ObserverSource $source,
        public array $rawPayload = [],
        public ?int $amountPaise = null,
        public ?string $amount = null,
        public ?CarbonImmutable $observedAt = null,
        public ?string $bankReferenceNumber = null,
        public ?string $senderVpa = null,
    ) {}
}
