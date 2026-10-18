<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerSession> */
class CustomerSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addDays(30),
            'is_active' => true,
            'ip_address' => null,
            'user_agent' => null,
        ];
    }
}
