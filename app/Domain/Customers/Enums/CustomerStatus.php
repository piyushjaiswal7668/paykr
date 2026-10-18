<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

enum CustomerStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BANNED = 'BANNED';
}
