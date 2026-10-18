<?php

declare(strict_types=1);

namespace App\Domain\Verification\Jobs;

use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Services\AttributionEngine;
use App\Models\PaymentObservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReattributePendingObservations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $merchantId, public readonly string $accountId)
    {
        $this->onConnection('redis')->onQueue('payments');
    }

    public function handle(TenantContext $tenants, AttributionEngine $engine): void
    {
        $tenants->run($this->merchantId, function () use ($engine): void {
            $observations = PaymentObservation::where('receiving_account_id', $this->accountId)->where('attribution_status', AttributionStatus::UNATTRIBUTED)
                ->whereNotNull('verified_at')->where('observed_at', '>=', now()->subMinutes(30))->orderBy('observed_at')->limit(100)->get();
            foreach ($observations as $observation) {
                $engine->reattribute($observation->id);
            }
        });
    }
}
