<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

enum OtpPurpose: string
{
    case LOGIN = 'LOGIN';
    case SIGNUP = 'SIGNUP';
}
