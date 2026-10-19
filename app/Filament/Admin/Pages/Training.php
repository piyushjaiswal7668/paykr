<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Support\TrainingLibrary;

final class Training extends AdminPage
{
    protected string $view = 'filament.shared.training';

    /** @return list<array{id: string, title: string, audience: string}> */
    public function lessons(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(TrainingLibrary::class)->lessons(true);
    }
}
