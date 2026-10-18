<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum SenderType: string
{
    case CUSTOMER = 'CUSTOMER';
    case ADMIN = 'ADMIN';
}
