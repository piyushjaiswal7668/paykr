<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

enum MerchantStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
}
