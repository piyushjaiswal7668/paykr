<?php

declare(strict_types=1);

namespace App\Domain\Observers\Jobs;

use App\Domain\Observers\Services\GmailPoller;
use App\Domain\Shared\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PollGmailNow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public readonly string $merchantId)
    {
        $this->onConnection('redis')->onQueue('observers');
    }

    public function handle(TenantContext $tenants, GmailPoller $poller): void
    {
        $tenants->run($this->merchantId, fn () => $poller->poll());
    }
}
