<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Jobs;

use App\Domain\Shared\Services\TenantContext;
use App\Domain\Webhooks\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly string $merchantId, public readonly string $deliveryId)
    {
        $this->onConnection('redis')->onQueue('webhooks');
    }

    public function handle(TenantContext $tenants, WebhookService $webhooks): void
    {
        $tenants->run($this->merchantId, fn () => $webhooks->deliver($this->deliveryId));
    }
}
