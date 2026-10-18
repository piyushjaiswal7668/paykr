<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

final readonly class PaymentApiResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data, public bool $created = false) {}
}
