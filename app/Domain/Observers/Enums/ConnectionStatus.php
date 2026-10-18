<?php

declare(strict_types=1);

namespace App\Domain\Observers\Enums;

enum ConnectionStatus: string
{
    case DISCONNECTED = 'DISCONNECTED';
    case CONFIGURED = 'CONFIGURED';
    case CONNECTED = 'CONNECTED';
    case ERROR = 'ERROR';
}
