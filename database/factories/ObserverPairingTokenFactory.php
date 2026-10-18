<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\ObserverPairingToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ObserverPairingToken> */
class ObserverPairingTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'consumed_by_device_id' => null,
        ];
    }
}
