<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Domain\Observers\Services\MessagesSidecar;
use App\Domain\Observers\Services\WhatsAppSidecar;
use App\Filament\Support\MerchantPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

final class Observers extends MerchantPage
{
    #[On('paykr-live-update')]
    public function refreshLive(): void {}

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.merchant.pages.observers';

    /** @return array<string, string> */
    public function health(): array
    {
        abort_unless(self::canAccess(), 403);

        return Cache::remember('panel-observers:'.Filament::getTenant()?->getKey(), 15, fn (): array => [
            'WhatsApp sender' => (app(WhatsAppSidecar::class)->status()['isConnected'] ?? false) ? 'Connected' : 'Unavailable',
            'Google Messages' => config('paykr.sidecars.messages_enabled') ? (app(MessagesSidecar::class)->health((string) Filament::getTenant()?->getKey())->status ?? 'Unavailable') : 'Disabled — Android observer is used',
        ]);
    }
}
