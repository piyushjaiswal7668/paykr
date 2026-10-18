<?php

declare(strict_types=1);

namespace App\Domain\Devices\Enums;

enum DeviceStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
