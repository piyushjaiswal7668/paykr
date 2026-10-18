<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

final readonly class ObserverResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}
}
