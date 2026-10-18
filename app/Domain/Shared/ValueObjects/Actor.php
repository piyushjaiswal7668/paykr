<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Enums\ActorType;

final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public ?string $merchantId = null,
        public ?string $ip = null,
    ) {}
}
