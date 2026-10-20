<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Services\PaymentNotifications;
use App\Domain\Shared\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverWhatsappNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly string $merchantId, public readonly string $notificationId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function handle(TenantContext $tenants, PaymentNotifications $notifications): void
    {
        $tenants->run($this->merchantId, fn () => $notifications->deliver($this->notificationId));
    }
}
