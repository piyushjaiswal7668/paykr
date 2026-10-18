<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\AuditLog;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'entity_type' => 'payment',
            'entity_id' => (string) Str::uuid(),
            'action' => 'TEST_ACTION',
            'actor_type' => 'SYSTEM',
            'actor_id' => null,
            'details' => [],
            'ip_address' => null,
        ];
    }
}
