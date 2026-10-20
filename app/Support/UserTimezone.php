<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Facades\Filament;

final class UserTimezone
{
    public static function current(): string
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? ($user->timezone ?? 'UTC') : 'UTC';
    }

    public static function format(?DateTimeInterface $date): string
    {
        return $date === null ? 'Never' : CarbonImmutable::instance($date)->setTimezone(self::current())->format('Y-m-d H:i:s T');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::current())->startOfDay();
    }
}
