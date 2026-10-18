<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\DeveloperPage;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

final class GmailConnection extends DeveloperPage
{
    protected static string $capability = 'observe';

    protected static string|\UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.merchant.pages.gmail-connection';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('credentials')->label('Configure OAuth client')->schema([TextInput::make('client_id')->required()->maxLength(500), TextInput::make('client_secret')->password()->required()->maxLength(500)])->action(fn (array $data) => $this->runTool('gmail-credentials', data: $data)),
            Action::make('connect')->action(fn () => $this->runTool('gmail-connect')),
            Action::make('test')->action(fn () => $this->runTool('gmail-test')),
            Action::make('disconnect')->color('danger')->requiresConfirmation()->action(fn () => $this->runTool('gmail-disconnect')),
        ];
    }
}
