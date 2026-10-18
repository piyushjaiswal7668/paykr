<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

enum MerchantRole: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case CASHIER = 'cashier';
    case DEVELOPER = 'developer';
}
