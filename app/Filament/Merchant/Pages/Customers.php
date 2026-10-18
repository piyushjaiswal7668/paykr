<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\Customer;
use App\Models\PaymentSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class Customers extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.merchant.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(Customer::whereIn('id', PaymentSession::select('customer_id')->whereNotNull('customer_id')))->columns([TextColumn::make('name')->searchable(), TextColumn::make('mobile_number')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('status')->label('Platform status')->badge()])->recordActions([
            Action::make('block')->label('Block for my store')->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(1000)])->requiresConfirmation()->action(fn (Customer $record, array $data) => $this->operate('customer-block', $record->id, $data)),
            Action::make('unblock')->label('Remove store block')->requiresConfirmation()->action(fn (Customer $record) => $this->operate('customer-unblock', $record->id)),
            Action::make('evidence')->modalSubmitAction(false)->modalContent(fn (Customer $record) => view('filament.merchant.customer-evidence', ['customer' => $record])),
        ]);
    }
}
