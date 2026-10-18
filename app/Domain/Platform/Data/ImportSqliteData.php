<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

final readonly class ImportSqliteData
{
    public function __construct(public string $path) {}
}
