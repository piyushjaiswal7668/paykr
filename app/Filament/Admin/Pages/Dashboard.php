<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\ObserverHealth;
use App\Filament\Admin\Widgets\PlatformStats;
use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [PlatformStats::class, ObserverHealth::class];
    }
}
