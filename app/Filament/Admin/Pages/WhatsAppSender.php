<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Observers\Services\WhatsAppSidecar;
use App\Filament\Support\AdminPage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class WhatsAppSender extends AdminPage
{
    protected string $view = 'filament.admin.pages.whatsapp-sender';

    /** @return array<string, mixed> */
    public function status(): array
    {
        abort_unless(self::canAccess(), 403);

        return array_intersect_key(app(WhatsAppSidecar::class)->status() ?? ['status' => 'Unavailable'], array_flip(['status', 'isConnected', 'phoneNumber', 'qrCode', 'pairingCode', 'pairingPhone']));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('connect')->label('Connect / reconnect')->action(fn () => $this->manage('whatsapp-connect')),
            Action::make('pair')->label('Link with phone number')->schema([TextInput::make('phone')->label('Country code and sender number')->required()->regex('/^[0-9]{10,15}$/D')])->action(fn (array $data) => $this->manage('whatsapp-pairing-code', data: $data)),
            Action::make('disconnect')->color('danger')->requiresConfirmation()->modalDescription('Logs out the sender and erases its saved connection credentials.')->action(fn () => $this->manage('whatsapp-disconnect')),
            Action::make('test')->label('Send test message')->requiresConfirmation()->schema([TextInput::make('phone')->label('Country code and recipient number')->required()->regex('/^[0-9]{10,15}$/D'), Textarea::make('message')->required()->maxLength(1000)])->action(fn (array $data) => $this->manage('whatsapp-test', data: $data))];
    }
}
