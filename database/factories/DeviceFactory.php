<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'public_key' => 'test-public-key-'.fake()->uuid(),
            'fingerprint_hash' => hash('sha256', fake()->uuid()),
            'user_agent' => null,
            'platform' => null,
            'browser' => null,
            'timezone' => null,
            'language' => null,
            'screen_signature' => null,
            'device_token_hash' => hash('sha256', fake()->uuid()),
            'status' => 'ACTIVE',
            'last_seen_at' => now(),
        ];
    }
}
