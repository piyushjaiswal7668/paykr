<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\DeveloperPage;
use App\Models\AndroidDevice;
use App\Models\ObserverPairingToken;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class AndroidSms extends DeveloperPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'observe';

    protected static string|\UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.merchant.pages.android-sms';

    protected function getHeaderActions(): array
    {
        return [Action::make('pair')->label('Create pairing QR')->action(fn () => $this->runTool('android-pair')),
            Action::make('download')->label('Download Android APK')->url('/paykr-sms-gateway.apk'),
            Action::make('parse')->label('Test bank SMS parser')->schema([Textarea::make('text')->required()->maxLength(65536)])->action(fn (array $data) => $this->runTool('parse-sms', data: $data))];
    }

    public function refreshPairing(): void
    {
        abort_unless(self::canAccess(), 403);
        if (! isset($this->result['sessionId'])) {
            return;
        }
        $token = ObserverPairingToken::find($this->result['sessionId']);
        if ($token === null || $token->consumed_at !== null || $token->expires_at->isPast()) {
            $this->runTool('android-pair');
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(AndroidDevice::query())->poll('10s')->columns([
            TextColumn::make('device_name'), TextColumn::make('device_model'),
            TextColumn::make('connection')->state(fn (AndroidDevice $record): string => ! $record->is_active ? 'Unpaired' : ($record->last_heartbeat_at?->greaterThan(now()->subMinutes(3)) ? 'Connected' : 'Offline'))->badge(),
            TextColumn::make('last_heartbeat_at')->dateTime(), TextColumn::make('last_observation_at')->dateTime(),
        ])->recordActions([Action::make('unpair')->visible(fn (AndroidDevice $record) => $record->is_active)->requiresConfirmation()->action(fn (AndroidDevice $record) => $this->runTool('android-unpair', $record->id))]);
    }
}
