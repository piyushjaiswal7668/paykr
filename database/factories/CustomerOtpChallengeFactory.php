<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerOtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerOtpChallenge> */
class CustomerOtpChallengeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mobile_number' => fake()->numerify('9#########'),
            'code_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'purpose' => 'LOGIN',
            'pending_name' => null,
            'pending_email' => null,
            'ip_address' => null,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
        ];
    }
}
