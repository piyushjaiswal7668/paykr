<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $this->redirect(LiveMonitor::getUrl());
    }
}
