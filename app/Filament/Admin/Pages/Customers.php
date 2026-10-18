<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Customers extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(Customer::query())->columns([TextColumn::make('name')->searchable(), TextColumn::make('mobile_number')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('status')->badge(), TextColumn::make('banned_at')->dateTime()])->filters([SelectFilter::make('status')->options(['ACTIVE' => 'Active', 'BANNED' => 'Banned'])])->recordActions([
            Action::make('ban')->color('danger')->requiresConfirmation()->schema([Textarea::make('notes')->required()->minLength(3)->maxLength(2000)])->action(fn (Customer $record, array $data) => $this->manage('customer-ban', $record->id, $data)),
            Action::make('unban')->requiresConfirmation()->schema([Textarea::make('notes')->required()->minLength(3)->maxLength(2000)])->action(fn (Customer $record, array $data) => $this->manage('customer-unban', $record->id, $data)),
            Action::make('evidence')->modalSubmitAction(false)->modalContent(fn (Customer $record) => view('filament.admin.customer-evidence', ['customer' => $record])),
        ]);
    }
}
