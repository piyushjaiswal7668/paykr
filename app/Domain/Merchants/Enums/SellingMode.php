<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

enum SellingMode: string
{
    case ONLINE = 'ONLINE';
    case OFFLINE = 'OFFLINE';
}
