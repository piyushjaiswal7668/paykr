<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\PaymentReceivingAccount;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class ReceivingAccounts extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Store settings';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.merchant.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(PaymentReceivingAccount::query())->columns([TextColumn::make('upi_id')->searchable(), TextColumn::make('payee_name'), IconColumn::make('is_active')->boolean(), IconColumn::make('is_merchant_enabled')->boolean(), IconColumn::make('is_slot_busy')->boolean()])->headerActions([
            Action::make('add')->schema([TextInput::make('upi_id')->label('UPI ID')->required()->maxLength(100), TextInput::make('payee_name')->required()->maxLength(100), Toggle::make('is_merchant_enabled')->label('Merchant UPI account')->default(true)])->action(fn (array $data) => $this->operate('account-create', data: $data)),
        ])->recordActions([
            Action::make('activate')->visible(fn (PaymentReceivingAccount $record) => ! $record->is_active)->requiresConfirmation()->action(fn (PaymentReceivingAccount $record) => $this->operate('account-activate', $record->id)),
            Action::make('deactivate')->visible(fn (PaymentReceivingAccount $record) => $record->is_active)->requiresConfirmation()->action(fn (PaymentReceivingAccount $record) => $this->operate('account-deactivate', $record->id)),
            Action::make('delete')->color('danger')->requiresConfirmation()->modalDescription('Accounts with payment history or live sessions cannot be deleted.')->action(fn (PaymentReceivingAccount $record) => $this->operate('account-delete', $record->id)),
        ]);
    }
}
