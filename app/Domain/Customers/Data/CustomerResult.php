<?php

declare(strict_types=1);

namespace App\Domain\Customers\Data;

final readonly class CustomerResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data, public ?string $token = null, public bool $created = false, public bool $logout = false) {}
}
