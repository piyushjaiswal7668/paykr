<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

final readonly class ImportSqliteResult
{
    /** @param array<string, int> $counts */
    public function __construct(public array $counts) {}
}
