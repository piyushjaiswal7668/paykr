<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Merchants\Enums\MerchantStatus;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Merchant> */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return ['business_name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'status' => MerchantStatus::ACTIVE, 'contact_email' => fake()->safeEmail(), 'allowed_origins' => []];
    }
}
