<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\AdminPage;
use App\Models\AuditLog;
use App\Models\Merchant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class AuditHistory extends AdminPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.table';

    public function table(Table $table): Table
    {
        return $table->query(AuditLog::withoutGlobalScope('merchant'))->defaultSort('created_at', 'desc')->columns([TextColumn::make('merchant.business_name'), TextColumn::make('action')->searchable(), TextColumn::make('actor_id')->searchable(), TextColumn::make('entity_id')->searchable(), TextColumn::make('actor_type')->badge(), TextColumn::make('ip_address'), TextColumn::make('created_at')->dateTime()])->filters([SelectFilter::make('merchant_id')->options(fn () => Merchant::pluck('business_name', 'id')->all())]);
    }
}
