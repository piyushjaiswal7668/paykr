<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\Merchant;
use App\Models\PaymentSession;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Payments extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(PaymentSession::withoutGlobalScope('merchant'))->defaultSort('created_at', 'desc')->columns([TextColumn::make('merchant.business_name'), TextColumn::make('order_id')->searchable(), TextColumn::make('amount_paise')->label('Amount')->money('INR', divideBy: 100), TextColumn::make('method')->badge(), TextColumn::make('status')->badge(), IconColumn::make('is_test')->boolean(), TextColumn::make('created_at')->dateTime()])->filters([SelectFilter::make('merchant_id')->options(fn () => Merchant::pluck('business_name', 'id')->all()), SelectFilter::make('status')->options(['ACTIVE' => 'Active', 'PROCESSING' => 'Processing', 'SUCCESS' => 'Success', 'FAILED' => 'Failed', 'EXPIRED' => 'Expired', 'CANCELLED' => 'Cancelled'])])->recordActions([
            Action::make('close')->label('Force close')->color('danger')->requiresConfirmation()->visible(fn (PaymentSession $record) => ! $record->status->isTerminal())->action(fn (PaymentSession $record) => $this->manage('payment-close', $record->id)),
        ]);
    }
}
