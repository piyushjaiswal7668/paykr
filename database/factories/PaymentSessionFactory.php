<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentSession> */
class PaymentSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'customer_id' => null,
            'receiving_account_id' => null,
            'device_id' => null,
            'order_id' => fake()->unique()->uuid(),
            'amount_paise' => 100,
            'currency' => 'INR',
            'method' => 'UPI',
            'status' => 'INITIATED',
            'queue_position' => 0,
            'slot_acquired_at' => null,
            'expires_at' => null,
            'processing_started_at' => null,
            'processing_expires_at' => null,
            'completed_at' => null,
            'return_url' => null,
            'attributed_observation_id' => null,
            'idempotency_key' => null,
            'verification_level' => 0,
            'verification_sources' => [],
            'verification_tag' => 'UNVERIFIED',
            'metadata' => [],
            'is_test' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::ACTIVE, 'expires_at' => now()->addMinutes(5)]);
    }

    public function forAccount(PaymentReceivingAccount $account): static
    {
        return $this->state(fn () => ['merchant_id' => $account->merchant_id, 'receiving_account_id' => $account->id]);
    }
}
