<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Filament\Support\MerchantPage;
use App\Models\SupportTicket;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class SupportTickets extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.merchant.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(SupportTicket::query())->defaultSort('created_at', 'desc')->columns([TextColumn::make('subject')->searchable(), TextColumn::make('customer.name'), TextColumn::make('status')->badge(), TextColumn::make('created_at')->dateTime()])->recordActions([
            Action::make('messages')->modalSubmitAction(false)->modalContent(fn (SupportTicket $record) => view('filament.merchant.ticket-detail', ['ticket' => $record])),
            Action::make('resolve')->requiresConfirmation()->action(fn (SupportTicket $record) => $this->operate('ticket-resolve', $record->id)),
        ]);
    }
}
