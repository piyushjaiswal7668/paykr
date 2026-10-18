<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\AndroidDevice;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

final class ObserverHealth extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table->query(Merchant::query())->heading('Observer health by merchant')->columns([
            TextColumn::make('business_name')->searchable(), TextColumn::make('status')->badge(),
            TextColumn::make('android')->state(fn (Merchant $record): string => AndroidDevice::withoutGlobalScope('merchant')->where('merchant_id', $record->id)->where('is_active', true)->where('last_heartbeat_at', '>', now()->subMinutes(3))->exists() ? 'Connected' : 'Offline'),
            TextColumn::make('gmail')->state(fn (Merchant $record): string => MerchantGmailConnection::withoutGlobalScope('merchant')->where('merchant_id', $record->id)->first()?->status->value ?? 'DISCONNECTED'),
        ]);
    }
}
