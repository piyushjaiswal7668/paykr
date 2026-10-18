<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

enum ApiKeyType: string
{
    case PUBLISHABLE = 'publishable';
    case SECRET = 'secret';
}
