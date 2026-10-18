<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

enum ApiKeyMode: string
{
    case LIVE = 'live';
    case TEST = 'test';
}
