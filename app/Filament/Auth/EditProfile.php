<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

final class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Select::make('timezone')->label('Display timezone')->required()->searchable()
                ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                ->rules(['timezone'])
                ->helperText('Dates and today’s totals use this timezone. Stored timestamps and API responses remain UTC.'),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
