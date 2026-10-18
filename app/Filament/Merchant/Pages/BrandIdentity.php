<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Domain\Management\Services\StoreSettings;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Filament\Support\MerchantPage;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

final class BrandIdentity extends MerchantPage
{
    protected static string $capability = 'configure';

    protected static string|\UnitEnum|null $navigationGroup = 'Store settings';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.merchant.pages.brand-identity';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill(MerchantBranding::first()?->only(array_keys(StoreSettings::brandingRules())) ?? ['app_name' => 'Paykr', 'primary_color' => '#2563eb', 'header_theme' => 'gradient']);
        $tenant = Filament::getTenant();
        $this->data['business_name'] = $tenant instanceof Merchant ? $tenant->business_name : null;
    }

    public function form(Schema $schema): Schema
    {
        $fields = [TextInput::make('business_name')->required()->maxLength(100)->live(), FileUpload::make('uploaded_logo')->label('Upload logo')->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->disk('public')->directory('brand-logos')->visibility('public')->maxSize(2048), TextInput::make('app_name')->required()->maxLength(80)->live(), ColorPicker::make('primary_color')->required()->live(), Select::make('header_theme')->options(['gradient' => 'Gradient', 'solid' => 'Solid', 'dark' => 'Dark'])->required()->live()];
        foreach (['logo_url', 'terms_url', 'privacy_policy_url'] as $field) {
            $fields[] = TextInput::make($field)->url()->maxLength(2048);
        }
        foreach (['sidebar_notice', 'subbanner_text', 'trust_badge_text', 'checkout_notice', 'footer_guarantee', 'counter_instructions', 'card_counter_instructions'] as $field) {
            $fields[] = Textarea::make($field)->maxLength(2000)->live(onBlur: true);
        }
        foreach (['hide_trust_badge', 'hide_subbanner', 'hide_powered_by', 'hide_time_saving_banner'] as $field) {
            $fields[] = Toggle::make($field)->live();
        }
        $fields[] = TagsInput::make('support_emails');
        $fields[] = TagsInput::make('support_mobiles');

        return $schema->components($fields)->statePath('data')->columns(2);
    }

    public function save(): void
    {
        NotifiesPaykrErrors::run(function (): bool {
            $data = $this->form->getState();
            if (! empty($data['uploaded_logo'])) {
                abort_unless(is_string($data['uploaded_logo']) && str_starts_with($data['uploaded_logo'], 'brand-logos/') && Storage::disk('public')->exists($data['uploaded_logo']), 422);
                $data['logo_url'] = url(Storage::disk('public')->url($data['uploaded_logo']));
            }
            unset($data['uploaded_logo']);
            app(StoreSettings::class)->save($this->command('branding', data: $data));
            Notification::make()->success()->title('Brand identity saved')->send();

            return true;
        });
    }
}
