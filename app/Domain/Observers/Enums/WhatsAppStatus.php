<?php

declare(strict_types=1);

namespace App\Domain\Observers\Enums;

enum WhatsAppStatus: string
{
    case DISCONNECTED = 'DISCONNECTED';
    case QR_READY = 'QR_READY';
    case PAIRING_CODE_READY = 'PAIRING_CODE_READY';
    case CONNECTING = 'CONNECTING';
    case CONNECTED = 'CONNECTED';
}
