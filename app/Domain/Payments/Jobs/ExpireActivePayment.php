<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Shared\Services\TenantContext;
use App\Models\PaymentSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExpireActivePayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $merchantId, public readonly string $paymentId)
    {
        $this->onConnection('redis')->onQueue('payments');
    }

    public function handle(TenantContext $tenants, PaymentTimingService $timing): void
    {
        $tenants->run($this->merchantId, function () use ($timing): void {
            if (PaymentSession::find($this->paymentId)?->status === PaymentStatus::ACTIVE) {
                $timing->expireIfDue($this->paymentId);
            }
        });
    }
}
