<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Webhooks\Services\WebhookRecoveryService;
use Illuminate\Console\Command;

final class RetryWebhooks extends Command
{
    protected $signature = 'paykr:webhooks:retry-pending';

    protected $description = 'Enqueue up to 100 due persisted webhook deliveries';

    public function handle(WebhookRecoveryService $recovery): int
    {
        $this->info('Due deliveries considered: '.$recovery->dispatchDue());

        return self::SUCCESS;
    }
}
