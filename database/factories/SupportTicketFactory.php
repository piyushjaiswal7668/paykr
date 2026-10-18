<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'customer_id' => Customer::factory(),
            'payment_id' => null,
            'subject' => fake()->word(),
            'status' => 'OPEN',
        ];
    }
}
