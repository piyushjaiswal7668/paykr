<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Management\Services\DeveloperTools;
use App\Filament\Concerns\NotifiesPaykrErrors;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;

abstract class DeveloperPage extends MerchantPage
{
    protected static string $capability = 'develop';

    /** @var array<string, mixed> */
    #[Locked]
    public array $result = [];

    /** @param array<string, mixed> $data */
    protected function runTool(string $operation, ?string $id = null, array $data = []): void
    {
        $result = NotifiesPaykrErrors::run(fn () => app(DeveloperTools::class)->execute($this->command($operation, $id, $data)));
        if (is_array($result)) {
            $this->result = $result;
            Notification::make()->success()->title('Action completed')->send();
            if (isset($result['redirect']) && is_string($result['redirect'])) {
                $this->redirect($result['redirect']);
            }
        }
    }
}
