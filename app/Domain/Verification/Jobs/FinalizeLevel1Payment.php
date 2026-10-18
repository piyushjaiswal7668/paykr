<?php

declare(strict_types=1);

namespace App\Domain\Verification\Jobs;

use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Services\AttributionEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FinalizeLevel1Payment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $merchantId, public readonly string $paymentId)
    {
        $this->onConnection('redis')->onQueue('payments');
    }

    public function handle(TenantContext $tenants, AttributionEngine $engine): void
    {
        $tenants->run($this->merchantId, fn () => $engine->finalizeLevel1($this->paymentId));
    }
}
