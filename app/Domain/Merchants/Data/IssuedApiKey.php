<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Data;

final readonly class IssuedApiKey
{
    public function __construct(public string $id, #[\SensitiveParameter] public string $key) {}
}
