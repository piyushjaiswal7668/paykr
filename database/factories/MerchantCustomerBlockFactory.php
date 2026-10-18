<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantCustomerBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantCustomerBlock> */
class MerchantCustomerBlockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'customer_id' => Customer::factory(),
            'reason' => fake()->word(),
            'blocked_by' => User::factory(),
        ];
    }
}
