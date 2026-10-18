<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\DeveloperPage;
use App\Models\Customer;
use App\Models\PaymentSession;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

final class PaymentSimulator extends DeveloperPage
{
    protected static string|\UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.merchant.pages.payment-simulator';

    protected function getHeaderActions(): array
    {
        return [Action::make('enable')->label('Enable test mode for 30 minutes')->requiresConfirmation()->action(fn () => $this->runTool('test-enable')),
            Action::make('disable')->label('Disable test mode')->action(fn () => $this->runTool('test-disable')),
            Action::make('inject')->label('Simulate bank observation')->schema([
                Select::make('customer_id')->label('Customer')->searchable()->live()->getSearchResultsUsing(fn (string $search): array => Customer::whereIn('id', PaymentSession::select('customer_id')->whereNotNull('customer_id'))->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('mobile_number', 'like', '%'.$search.'%'))->limit(30)->pluck('name', 'id')->all())->helperText('Leave empty to select an anonymous test session.'),
                Select::make('payment_id')->label('Test payment session')->required()->options(fn (Get $get): array => PaymentSession::where('is_test', true)->where('customer_id', $get('customer_id'))->whereIn('status', ['ACTIVE', 'PROCESSING'])->pluck('order_id', 'id')->all()),
                Select::make('source')->options(['SMS' => 'SMS stream', 'GMAIL' => 'Gmail stream'])->required(), TextInput::make('sender')->required()->maxLength(255), Textarea::make('text')->required()->maxLength(65536), DateTimePicker::make('received_at')->default(now())->required(),
            ])->action(fn (array $data) => $this->runTool('simulate', $data['payment_id'], $data))];
    }
}
