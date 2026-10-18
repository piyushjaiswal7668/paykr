<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\PaymentSession;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\On;

final class Transactions extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    #[On('paykr-live-update')]
    public function refreshLive(): void {}

    protected static string $capability = 'view';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.merchant.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(PaymentSession::query())->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('order_id')->searchable(), TextColumn::make('id')->label('Payment ID')->searchable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('amount_paise')->label('Amount')->money('INR', divideBy: 100), TextColumn::make('method')->badge(), TextColumn::make('status')->badge(), TextColumn::make('verification_tag'), TextColumn::make('verification_sources')->listWithLineBreaks(), TextColumn::make('created_at')->dateTime(), TextColumn::make('completed_at')->dateTime(),
        ])->filters([SelectFilter::make('status')->options(array_combine(['INITIATED', 'QUEUED', 'ACTIVE', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'], ['INITIATED', 'QUEUED', 'ACTIVE', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'])), SelectFilter::make('method')->options(['UPI' => 'UPI', 'COD' => 'COD', 'CASH_COUNTER' => 'Cash counter', 'CARD_COUNTER' => 'Card counter'])])->recordActions([
            Action::make('detail')->modalHeading('Payment history')->modalSubmitAction(false)->modalContent(fn (PaymentSession $record) => view('filament.merchant.payment-detail', ['payment' => $record])),
        ]);
    }
}
