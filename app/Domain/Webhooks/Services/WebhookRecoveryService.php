<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

use App\Domain\Shared\Services\TenantContext;
use App\Models\WebhookDelivery;

final class WebhookRecoveryService
{
    public function __construct(private TenantContext $tenants, private WebhookService $webhooks) {}

    public function dispatchDue(): int
    {
        // Explicit system-worker boundary. Each queued job re-enters its own merchant context.
        return $this->tenants->runUnscoped(function (): int {
            $due = WebhookDelivery::where('is_successful', false)->where('attempt_count', '<', $this->webhooks->maxAttempts())
                ->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now()->format('Y-m-d H:i:s.v'))->orderBy('next_retry_at')->limit(100)->get();
            foreach ($due as $delivery) {
                $this->webhooks->schedule($delivery);
            }

            return $due->count();
        });
    }
}
