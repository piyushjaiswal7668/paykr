<?php

declare(strict_types=1);

namespace App\Domain\Checkout\Data;

final readonly class CheckoutResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data, public bool $created = false, public bool $gone = false, public ?string $svg = null) {}
}
