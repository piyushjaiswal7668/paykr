<?php

declare(strict_types=1);

namespace App\Domain\Observations\Enums;

enum ObserverSource: string
{
    case GMAIL = 'GMAIL';
    case GOOGLE_MESSAGES = 'GOOGLE_MESSAGES';
    case ANDROID_OBSERVER = 'ANDROID_OBSERVER';
    case SMS_GATEWAY = 'SMS_GATEWAY';
    case MANUAL_COUNTER = 'MANUAL_COUNTER';
}
