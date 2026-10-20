<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\WhatsappNotification;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class Notifications extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.merchant.pages.table';

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->fillForm(fn () => Filament::getTenant()?->only(['whatsapp_phone', 'whatsapp_merchant_enabled', 'whatsapp_customer_enabled', 'workflow_webhooks_enabled']))
            ->schema([
                TextInput::make('whatsapp_phone')->label('Merchant WhatsApp number')->helperText('Indian mobile number: 10 digits, without +91.')->maxLength(10),
                Toggle::make('whatsapp_merchant_enabled')->label('Send merchant WhatsApp updates'),
                Toggle::make('whatsapp_customer_enabled')->label('Send customer WhatsApp updates')->helperText('Requires customer consent for each payment. Uses the platform WhatsApp sender.'),
                Toggle::make('workflow_webhooks_enabled')->label('Send workflow events to website')->helperText('Uses your configured webhook URL and signing secret. Enable after updating your receiver.'),
            ])->action(fn (array $data) => $this->operate('notification-settings', data: $data))];
    }

    public function table(Table $table): Table
    {
        return $table->query(WhatsappNotification::query())->defaultSort('created_at', 'desc')->poll('10s')->columns([
            TextColumn::make('payment_id')->searchable(), TextColumn::make('event'), TextColumn::make('audience'), TextColumn::make('status')->badge(), TextColumn::make('attempts'), TextColumn::make('sent_at')->dateTime(), TextColumn::make('created_at')->dateTime(),
        ])->recordActions([Action::make('retry')->visible(fn (WhatsappNotification $record) => in_array($record->status, ['FAILED', 'UNKNOWN'], true))->requiresConfirmation()
            ->modalDescription('An UNKNOWN message may already have arrived. Wait at least five minutes and check WhatsApp before retrying to avoid duplicates.')
            ->action(fn (WhatsappNotification $record) => $this->operate('notification-retry', $record->id))]);
    }
}
