<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Domain\Management\Services\TeamManagement;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Filament\Support\MerchantPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

final class TeamMembers extends MerchantPage implements HasTable
{
    use InteractsWithTable;

    protected static string $capability = 'team';

    protected static string|\UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.merchant.pages.developer-table';

    /** @var array<string, mixed> */
    #[Locked]
    public array $result = [];

    /** @param array<string, mixed> $data */
    protected function manage(string $operation, ?string $id = null, array $data = []): void
    {
        $result = NotifiesPaykrErrors::run(fn () => app(TeamManagement::class)->execute($this->command($operation, $id, $data)));
        if (! is_array($result)) {
            return;
        }
        if (isset($result['invitationToken'])) {
            $result['invitationUrl'] = Filament::getPanel('merchant')->getResetPasswordUrl($result['invitationToken'], User::findOrFail($result['userId']));
            unset($result['invitationToken']);
        }
        $this->result = $result;
    }

    public function table(Table $table): Table
    {
        $roles = ['owner' => 'Owner', 'manager' => 'Manager', 'cashier' => 'Cashier', 'developer' => 'Developer'];
        $tenant = (string) Filament::getTenant()?->getKey();

        return $table->query(User::whereHas('merchants', fn ($query) => $query->whereKey($tenant)))->columns([
            TextColumn::make('name'), TextColumn::make('email'), TextColumn::make('role')->state(fn (User $record) => $record->merchants()->whereKey($tenant)->first()?->getRelation('pivot')->role),
        ])->headerActions([Action::make('invite')->label('Add team member')->schema([TextInput::make('name')->required(), TextInput::make('email')->email()->required(), Select::make('role')->options($roles)->required()])->action(fn (array $data) => $this->manage('invite', data: $data))])
            ->recordActions([Action::make('role')->schema([Select::make('role')->options($roles)->required()])->action(fn (User $record, array $data) => $this->manage('role', $record->id, $data)), Action::make('remove')->color('danger')->requiresConfirmation()->action(fn (User $record) => $this->manage('remove', $record->id))]);
    }
}
