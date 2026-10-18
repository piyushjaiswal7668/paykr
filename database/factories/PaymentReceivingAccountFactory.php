<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentReceivingAccount> */
class PaymentReceivingAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'upi_id' => fake()->unique()->lexify('merchant????').'@upi',
            'payee_name' => fake()->company(),
            'is_merchant_enabled' => true,
            'is_active' => false,
            'is_slot_busy' => false,
            'current_active_session_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
