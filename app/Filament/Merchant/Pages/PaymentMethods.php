<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Domain\Management\Services\StoreSettings;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Filament\Support\MerchantPage;
use App\Models\MerchantBranding;
use App\Models\MerchantPaymentSettings;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

final class PaymentMethods extends MerchantPage
{
    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Store settings';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.merchant.pages.settings-form';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $settings = MerchantPaymentSettings::first();
        $this->form->fill(['selling_mode' => $settings?->selling_mode->value ?? 'ONLINE', 'cod_enabled' => $settings->cod_enabled ?? false, 'cash_enabled' => $settings->cash_enabled ?? false, 'card_enabled' => $settings->card_enabled ?? false] + (MerchantBranding::first()?->only(['counter_instructions', 'card_counter_instructions']) ?? []));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([Radio::make('selling_mode')->options(['ONLINE' => 'Online store: UPI and cash on delivery', 'OFFLINE' => 'In-store: UPI, cash and card at counter'])->required(), Toggle::make('cod_enabled'), Toggle::make('cash_enabled'), Toggle::make('card_enabled'), Textarea::make('counter_instructions')->maxLength(2000), Textarea::make('card_counter_instructions')->maxLength(2000)])->statePath('data');
    }

    public function save(): void
    {
        NotifiesPaykrErrors::run(function (): bool {
            app(StoreSettings::class)->save($this->command('methods', data: $this->form->getState()));
            Notification::make()->success()->title('Payment methods saved')->send();

            return true;
        });
    }
}
