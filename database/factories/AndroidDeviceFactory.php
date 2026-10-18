<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\AndroidDevice;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AndroidDevice> */
class AndroidDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'device_name' => fake()->word(),
            'device_model' => null,
            'signing_key' => bin2hex(random_bytes(32)),
            'is_active' => true,
            'last_heartbeat_at' => null,
            'last_observation_at' => null,
        ];
    }
}
