<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

final readonly class QueueUpdated
{
    /** @param array<string, int> $positions */
    public function __construct(public string $merchantId, public string $receivingAccountId, public array $positions) {}
}
