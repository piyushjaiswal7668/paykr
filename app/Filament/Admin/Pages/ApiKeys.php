<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\MerchantApiKey;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class ApiKeys extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(MerchantApiKey::withoutGlobalScope('merchant'))->columns([TextColumn::make('merchant.business_name'), TextColumn::make('id')->searchable(), TextColumn::make('prefix'), TextColumn::make('type'), TextColumn::make('mode'), TextColumn::make('revoked_at')->dateTime()])->recordActions([Action::make('revoke')->color('danger')->requiresConfirmation()->visible(fn (MerchantApiKey $record) => $record->revoked_at === null)->action(fn (MerchantApiKey $record) => $this->manage('key-revoke', $record->id))]);
    }
}
