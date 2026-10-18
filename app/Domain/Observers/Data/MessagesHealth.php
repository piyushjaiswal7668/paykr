<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

use Carbon\CarbonImmutable;

/** Snapshot from the authenticated merchant-specific sidecar adapter (Phase 6). */
final readonly class MessagesHealth
{
    public function __construct(
        public string $merchantId,
        public CarbonImmutable $checkedAt,
        public bool $paired,
        public string $status,
        public string $mode,
        public bool $polling,
        public ?CarbonImmutable $lastSeenAt = null,
    ) {}
}
