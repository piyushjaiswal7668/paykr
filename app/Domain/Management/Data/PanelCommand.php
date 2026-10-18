<?php

declare(strict_types=1);

namespace App\Domain\Management\Data;

final readonly class PanelCommand
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $userId, public string $merchantId, public string $operation, public ?string $recordId = null, public array $data = [], public ?string $ip = null) {}
}
