<?php

declare(strict_types=1);

namespace App\Domain\Observers\Jobs;

use App\Domain\Observers\Services\MessagesSidecar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SweepMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $merchantId)
    {
        $this->onConnection('redis')->onQueue('observers');
    }

    public function handle(): void
    {
        app(MessagesSidecar::class)->sweep($this->merchantId);
    }
}
