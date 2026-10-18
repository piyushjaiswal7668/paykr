<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

use App\Domain\Observers\Enums\WhatsAppStatus;

final readonly class WhatsAppState
{
    public function __construct(public WhatsAppStatus $status, public ?string $phoneNumber) {}
}
