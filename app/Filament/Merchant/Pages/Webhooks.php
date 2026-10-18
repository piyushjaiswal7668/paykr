<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\DeveloperPage;
use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class Webhooks extends DeveloperPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.merchant.pages.developer-table';

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->fillForm(fn () => Filament::getTenant()?->only(['webhook_url', 'allowed_origins']))
            ->schema([TextInput::make('webhook_url')->url()->maxLength(2048), TagsInput::make('allowed_origins')->helperText('Exact browser origins, including scheme and optional port.')])
            ->action(fn (array $data) => $this->runTool('webhook-settings', data: $data)),
            Action::make('reveal')->label('Reveal signing secret')->requiresConfirmation()->action(fn () => $this->runTool('webhook-reveal')),
            Action::make('rotate')->label('Rotate signing secret')->color('danger')->requiresConfirmation()->modalDescription('Update your webhook receiver immediately after rotating.')->action(fn () => $this->runTool('webhook-rotate')),
            Action::make('test')->label('Send test webhook')->requiresConfirmation()->modalDescription('Queues a clearly marked test payment event to your configured endpoint.')->action(fn () => $this->runTool('webhook-test')),
            Action::make('keys')->label('API keys')->url(ApiKeys::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(WebhookDelivery::query())->defaultSort('created_at', 'desc')->poll('10s')->columns([
            TextColumn::make('payment_id')->searchable(), TextColumn::make('event_type')->badge(), IconColumn::make('is_successful')->boolean(), TextColumn::make('attempt_count'), TextColumn::make('response_status'), TextColumn::make('next_retry_at')->dateTime(), TextColumn::make('created_at')->dateTime(),
        ])->recordActions([Action::make('resend')->requiresConfirmation()->action(fn (WebhookDelivery $record) => $this->runTool('webhook-resend', $record->id))]);
    }
}
