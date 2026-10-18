<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\Merchant;
use App\Models\SecurityEvent;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Security extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(SecurityEvent::withoutGlobalScope('merchant'))->defaultSort('created_at', 'desc')->columns([TextColumn::make('merchant.business_name'), TextColumn::make('customer.name')->searchable(), TextColumn::make('event_type')->searchable(), TextColumn::make('source')->badge(), TextColumn::make('fraud_reason'), TextColumn::make('created_at')->dateTime()])->filters([SelectFilter::make('event_type')->options(['MANUAL_BAN' => 'Manual bans', 'FRAUDULENT_PAYMENT_INJECTION' => 'Fraud events', 'USER_UNBANNED' => 'Unban history']), SelectFilter::make('merchant_id')->options(fn () => Merchant::pluck('business_name', 'id')->all())])->recordActions([Action::make('evidence')->modalSubmitAction(false)->modalContent(fn (SecurityEvent $record) => view('filament.admin.security-evidence', ['event' => $record]))]);
    }
}
