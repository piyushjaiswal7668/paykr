<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ActorType: string
{
    case PLATFORM_ADMIN = 'PLATFORM_ADMIN';
    case MERCHANT_USER = 'MERCHANT_USER';
    case MERCHANT_API_KEY = 'MERCHANT_API_KEY';
    case CUSTOMER = 'CUSTOMER';
    case DEVICE = 'DEVICE';
    case SIDECAR = 'SIDECAR';
    case SYSTEM = 'SYSTEM';
}
