<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Data;

final readonly class ApiIdentity
{
    public function __construct(public string $merchantId, public bool $isTest, public bool $secret) {}
}
