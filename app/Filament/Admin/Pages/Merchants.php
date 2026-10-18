<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\Merchant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class Merchants extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(Merchant::query())->columns([TextColumn::make('business_name')->searchable(), TextColumn::make('slug')->searchable(), TextColumn::make('contact_email'), TextColumn::make('status')->badge()])->headerActions([
            Action::make('create')->schema($this->fields(true))->action(fn (array $data) => $this->manage('merchant-save', data: $data)),
        ])->recordActions([
            Action::make('edit')->fillForm(fn (Merchant $record) => $record->only(['business_name', 'slug', 'contact_email', 'contact_phone']))->schema($this->fields(false))->action(fn (Merchant $record, array $data) => $this->manage('merchant-save', $record->id, $data)),
            Action::make('suspend')->requiresConfirmation()->visible(fn (Merchant $record) => $record->status->value === 'ACTIVE')->action(fn (Merchant $record) => $this->manage('merchant-suspend', $record->id)),
            Action::make('activate')->requiresConfirmation()->visible(fn (Merchant $record) => $record->status->value === 'SUSPENDED')->action(fn (Merchant $record) => $this->manage('merchant-activate', $record->id)),
            Action::make('impersonate')->label('Open as store owner')->requiresConfirmation()->modalDescription('Starts a logged 15-minute support session as this merchant’s owner. Your administrator identity is retained in mutation audits.')->action(fn (Merchant $record) => $this->manage('impersonate', $record->id)),
            Action::make('keys')->label('API key prefixes')->modalSubmitAction(false)->modalContent(fn (Merchant $record) => view('filament.admin.merchant-keys', ['merchant' => $record])),
        ]);
    }

    /** @return array<Component> */
    private function fields(bool $create): array
    {
        $fields = [TextInput::make('business_name')->required()->maxLength(100), TextInput::make('slug')->required()->maxLength(100), TextInput::make('contact_email')->email()->required(), TextInput::make('contact_phone')->maxLength(30)];
        if ($create) {
            $fields[] = TextInput::make('owner_name')->required();
            $fields[] = TextInput::make('owner_email')->email()->required();
        }

        return $fields;
    }
}
