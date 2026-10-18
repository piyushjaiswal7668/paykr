<?php

declare(strict_types=1);

namespace App\Domain\Payments\Listeners;

use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Services\TenantContext;

final class PromotePaymentQueue
{
    public function __construct(private TenantContext $tenants, private SlotQueueService $queue) {}

    public function handle(PaymentStateChanged $event): void
    {
        if ($event->receivingAccountId !== null) {
            $this->tenants->run($event->merchantId, fn () => $this->queue->promoteNext($event->receivingAccountId));
        }
    }
}
