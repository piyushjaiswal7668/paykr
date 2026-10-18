<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\MerchantOperations;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Models\Merchant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/** @property Schema $form */
abstract class MerchantPage extends Page
{
    protected static string $capability = 'view';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Merchant && Gate::forUser($user)->allows(static::$capability, $tenant);
    }

    public function booted(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @param array<string, mixed> $data */
    protected function command(string $operation, ?string $recordId = null, array $data = []): PanelCommand
    {
        abort_unless(static::canAccess(), 403);

        return new PanelCommand((string) Filament::auth()->id(), (string) Filament::getTenant()?->getKey(), $operation, $recordId, $data, request()->ip());
    }

    /** @param array<string, mixed> $data */
    protected function operate(string $operation, ?string $id = null, array $data = []): void
    {
        NotifiesPaykrErrors::run(function () use ($operation, $id, $data): bool {
            app(MerchantOperations::class)->execute($this->command($operation, $id, $data));
            Notification::make()->success()->title('Changes saved')->send();

            return true;
        });
    }
}
