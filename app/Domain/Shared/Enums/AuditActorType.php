<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum AuditActorType: string
{
    case SYSTEM = 'SYSTEM';
    case CUSTOMER = 'CUSTOMER';
    case ADMIN = 'ADMIN';
    case OBSERVER = 'OBSERVER';
    case PLATFORM_ADMIN = 'PLATFORM_ADMIN';
    case MERCHANT_USER = 'MERCHANT_USER';
    case MERCHANT_API_KEY = 'MERCHANT_API_KEY';
    case DEVICE = 'DEVICE';
    case SIDECAR = 'SIDECAR';
}
