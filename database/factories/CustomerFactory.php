<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password_hash' => null,
            'password_needs_rehash' => false,
            'is_phone_verified' => false,
            'status' => 'ACTIVE',
            'ban_reason' => null,
            'last_device' => null,
            'banned_at' => null,
            'unbanned_at' => null,
            'last_login_at' => null,
        ];
    }

    public function banned(): static
    {
        return $this->state(fn () => ['status' => CustomerStatus::BANNED, 'banned_at' => now()]);
    }
}
