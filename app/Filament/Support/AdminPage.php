<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\PlatformOperations;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

abstract class AdminPage extends Page
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $result = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }

    public function booted(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @param array<string, mixed> $data */
    protected function manage(string $operation, ?string $id = null, array $data = []): void
    {
        abort_unless(static::canAccess(), 403);
        $result = NotifiesPaykrErrors::run(fn () => app(PlatformOperations::class)->execute(new PanelCommand((string) Filament::auth()->id(), '', $operation, $id, $data, request()->ip())));
        if (! is_array($result)) {
            return;
        }
        if (isset($result['invitationToken'])) {
            $result['invitationUrl'] = Filament::getPanel('merchant')->getResetPasswordUrl($result['invitationToken'], User::findOrFail($result['userId']));
            unset($result['invitationToken']);
        }
        if ($operation === 'impersonate') {
            session(['paykr_impersonator' => (string) Filament::auth()->id(), 'paykr_impersonation_expires' => now()->addMinutes(15)->timestamp]);
            auth('web')->loginUsingId($result['userId']);
            request()->session()->regenerate();
            $this->redirect('/merchant/'.$result['merchantSlug'].'/live-monitor');
        }
        $this->result = $result;
    }
}
