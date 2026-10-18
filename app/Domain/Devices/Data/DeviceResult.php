<?php

declare(strict_types=1);

namespace App\Domain\Devices\Data;

final readonly class DeviceResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}
}
