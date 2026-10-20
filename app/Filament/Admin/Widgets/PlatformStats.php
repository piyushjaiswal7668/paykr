<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use App\Support\UserTimezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class PlatformStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $query = PaymentSession::withoutGlobalScope('merchant')->where('is_test', false);
        $today = (clone $query)->where('created_at', '>=', UserTimezone::today()->utc())
            ->where('created_at', '<', UserTimezone::today()->addDay()->utc());
        $week = (clone $query)->where('created_at', '>=', now()->subDays(7));
        $total = (clone $week)->count();
        $success = (clone $week)->where('status', 'SUCCESS')->count();

        return [Stat::make('Volume today', '₹'.number_format((int) (clone $today)->where('status', 'SUCCESS')->sum('amount_paise') / 100, 2)),
            Stat::make('Volume in 7 days', '₹'.number_format((int) (clone $week)->where('status', 'SUCCESS')->sum('amount_paise') / 100, 2)),
            Stat::make('7-day success rate', $total ? round(100 * $success / $total, 1).'%' : '—'),
            Stat::make('Active sessions', (clone $query)->whereIn('status', ['ACTIVE', 'PROCESSING'])->count()),
            Stat::make('Waiting queue', (clone $query)->where('status', 'QUEUED')->count()),
            Stat::make('Failed / pending webhooks', WebhookDelivery::withoutGlobalScope('merchant')->where('is_successful', false)->count())];
    }
}
