<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Shared\ValueObjects\Actor;

final class PanelAuditContext
{
    public ?Actor $actor = null;

    public ?string $impersonatedUserId = null;
}
