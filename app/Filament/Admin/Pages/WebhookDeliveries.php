<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class WebhookDeliveries extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(WebhookDelivery::withoutGlobalScope('merchant'))->defaultSort('created_at', 'desc')->columns([TextColumn::make('merchant.business_name'), TextColumn::make('payment_id')->searchable(), TextColumn::make('event_type')->badge(), IconColumn::make('is_successful')->boolean(), TextColumn::make('attempt_count'), TextColumn::make('response_status'), TextColumn::make('created_at')->dateTime()])->filters([SelectFilter::make('merchant_id')->options(fn () => Merchant::pluck('business_name', 'id')->all()), SelectFilter::make('is_successful')->options(['0' => 'Failed / pending', '1' => 'Delivered'])])->recordActions([Action::make('resend')->requiresConfirmation()->action(fn (WebhookDelivery $record) => $this->manage('webhook-resend', $record->id))]);
    }
}
