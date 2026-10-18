<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\PaymentSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\On;

final class CounterDesk extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    #[On('paykr-live-update')]
    public function refreshLive(): void {}

    protected static string $capability = 'counter';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.merchant.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(PaymentSession::whereIn('method', ['CASH_COUNTER', 'CARD_COUNTER'])->where('status', 'ACTIVE'))->defaultSort('created_at')->poll('5s')->columns([
            TextColumn::make('order_id')->searchable(), TextColumn::make('customer.name'), TextColumn::make('customer.mobile_number')->label('Mobile'), TextColumn::make('amount_paise')->label('Amount')->money('INR', divideBy: 100), TextColumn::make('method')->badge(), TextColumn::make('created_at')->dateTime(),
        ])->recordActions([
            Action::make('confirm')->requiresConfirmation()->modalDescription('Confirm that the full payment was received at the counter.')->action(fn (PaymentSession $record) => $this->operate('counter-confirm', $record->id)),
            Action::make('decline')->color('danger')->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(1000)])->requiresConfirmation()->action(fn (PaymentSession $record, array $data) => $this->operate('counter-decline', $record->id, $data)),
        ]);
    }
}
