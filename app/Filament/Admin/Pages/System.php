<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Platform\Services\ClockStatus;
use App\Domain\Platform\Services\SystemOverview;
use App\Filament\Support\AdminPage;

final class System extends AdminPage
{
    protected string $view = 'filament.admin.pages.system';

    /** @return array<string, mixed> */
    public function clockStatus(): array
    {
        return app(ClockStatus::class)->read();
    }

    /** @return array<string, mixed> */
    public function services(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(SystemOverview::class)->read();
    }

    /** @return list<array<string, mixed>> */
    public function failedJobs(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(SystemOverview::class)->failedJobs();
    }
}
