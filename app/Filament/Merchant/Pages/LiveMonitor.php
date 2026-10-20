<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\PaymentSession;
use App\Support\UserTimezone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

final class LiveMonitor extends MerchantPage
{
    #[On('paykr-live-update')]
    public function refreshLive(): void {}

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.merchant.pages.live-monitor';

    protected function getHeaderActions(): array
    {
        return [Action::make('refresh')->label('Force refresh')->action(fn () => null),
            Action::make('clear')->label('Clear waiting queue')->color('danger')->requiresConfirmation()
                ->visible(fn () => Gate::forUser(Filament::auth()->user())->allows('configure', Filament::getTenant()))
                ->action(fn () => $this->operate('clear-queue'))];
    }

    /** @return array<string, int|string> */
    public function stats(): array
    {
        $today = PaymentSession::where('is_test', false)->where('created_at', '>=', UserTimezone::today()->utc())
            ->where('created_at', '<', UserTimezone::today()->addDay()->utc());

        return [
            "Today's volume" => '₹'.number_format((int) (clone $today)->where('status', 'SUCCESS')->sum('amount_paise') / 100, 2),
            'Successful payments' => (clone $today)->where('status', 'SUCCESS')->count(),
            'In waiting queue' => PaymentSession::where('status', 'QUEUED')->count(),
            'Pending counter payments' => PaymentSession::where('status', 'ACTIVE')->whereIn('method', ['CASH_COUNTER', 'CARD_COUNTER'])->count(),
            'Single verified' => (clone $today)->where('verification_level', 1)->count(),
            'Dual verified' => (clone $today)->where('verification_level', '>=', 2)->count(),
        ];
    }
}
