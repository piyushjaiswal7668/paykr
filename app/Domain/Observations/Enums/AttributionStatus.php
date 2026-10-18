<?php

declare(strict_types=1);

namespace App\Domain\Observations\Enums;

enum AttributionStatus: string
{
    case UNATTRIBUTED = 'UNATTRIBUTED';
    case ATTRIBUTED = 'ATTRIBUTED';
    case LATE_UNMATCHED = 'LATE_UNMATCHED';
    case DUPLICATE_IGNORED = 'DUPLICATE_IGNORED';
}
