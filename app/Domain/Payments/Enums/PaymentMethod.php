<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

enum PaymentMethod: string
{
    case UPI = 'UPI';
    case COD = 'COD';
    case CASH_COUNTER = 'CASH_COUNTER';
    case CARD_COUNTER = 'CARD_COUNTER';
}
