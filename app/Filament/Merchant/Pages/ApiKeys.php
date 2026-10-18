<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\DeveloperPage;
use App\Models\MerchantApiKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class ApiKeys extends DeveloperPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.merchant.pages.developer-table';

    public function table(Table $table): Table
    {
        return $table->query(MerchantApiKey::query())->columns([TextColumn::make('id')->label('Key ID'), TextColumn::make('prefix'), TextColumn::make('type')->badge(), TextColumn::make('mode')->badge(), TextColumn::make('last_used_at')->dateTime(), TextColumn::make('revoked_at')->dateTime()])->headerActions([
            Action::make('issue')->schema([Select::make('type')->options(['publishable' => 'Publishable browser key', 'secret' => 'Secret server key'])->required(), Select::make('mode')->options(['live' => 'Live', 'test' => 'Test'])->required()])->action(fn (array $data) => $this->runTool('key-issue', data: $data)),
        ])->recordActions([
            Action::make('rotate')->requiresConfirmation()->visible(fn (MerchantApiKey $record) => $record->revoked_at === null)->action(fn (MerchantApiKey $record) => $this->runTool('key-rotate', $record->id)),
            Action::make('revoke')->requiresConfirmation()->color('danger')->visible(fn (MerchantApiKey $record) => $record->revoked_at === null)->action(fn (MerchantApiKey $record) => $this->runTool('key-revoke', $record->id)),
        ]);
    }
}
