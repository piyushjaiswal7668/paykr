<?php

declare(strict_types=1);

namespace App\Domain\Verification\Data;

final readonly class AttributionResult
{
    /** @param list<string> $verificationSources */
    public function __construct(
        public bool $attributed,
        public string $observationId,
        public string $outcomeStatus,
        public ?string $paymentSessionId = null,
        public bool $duplicate = false,
        public bool $lateMatch = false,
        public int $verificationLevel = 0,
        public ?string $verificationTag = null,
        public array $verificationSources = [],
    ) {}
}
