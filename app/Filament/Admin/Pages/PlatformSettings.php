<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Management\Services\PlatformSettings as Settings;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Filament\Support\AdminPage;
use App\Models\PlatformOption;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** @property Schema $form */
final class PlatformSettings extends AdminPage
{
    protected string $view = 'filament.merchant.pages.settings-form';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill(PlatformOption::find('defaults')->value ?? ['app_name' => config('paykr.defaults.app_name'), 'primary_color' => config('paykr.defaults.primary_color'), 'otp_max_attempts' => 5, 'otp_resend_cooldown_seconds' => 30, 'otp_sends_per_mobile_10_minutes' => 3, 'otp_sends_per_ip_hour' => 10]);
    }

    public function form(Schema $schema): Schema
    {
        $fields = [TextInput::make('app_name')->required()->maxLength(80), ColorPicker::make('primary_color')->required()];
        foreach (['otp_max_attempts', 'otp_resend_cooldown_seconds', 'otp_sends_per_mobile_10_minutes', 'otp_sends_per_ip_hour'] as $field) {
            $fields[] = TextInput::make($field)->numeric()->required();
        }

        return $schema->components($fields)->statePath('data');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        NotifiesPaykrErrors::run(function (): bool {
            app(Settings::class)->save((string) Filament::auth()->id(), $this->form->getState(), request()->ip());
            Notification::make()->success()->title('Platform defaults saved')->send();

            return true;
        });
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('apk')->label('Publish Android APK')->requiresConfirmation()->schema([TextInput::make('version')->required(), FileUpload::make('apk')->storeFiles(false)->maxSize(51200)->required()])->action(function (array $data): void {
            abort_unless(static::canAccess() && $data['apk'] instanceof TemporaryUploadedFile, 403);
            $result = NotifiesPaykrErrors::run(fn () => app(Settings::class)->publishApk((string) Filament::auth()->id(), $data['apk']->getRealPath(), $data['version'], request()->ip()));
            if (is_array($result)) {
                Notification::make()->success()->title('APK published: '.$result['version'])->body('SHA256: '.$result['sha256'])->send();
            }
        })];
    }
}
