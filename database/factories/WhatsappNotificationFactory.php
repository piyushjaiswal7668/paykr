<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\WhatsappNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WhatsappNotification> */
class WhatsappNotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'payment_id' => fn (array $attributes) => PaymentSession::factory()->create(['merchant_id' => $attributes['merchant_id']])->id,
            'event' => 'ORDER_RECEIVED', 'audience' => 'merchant', 'recipient' => '919123456789', 'message' => 'Test notification',
            'status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now(),
        ];
    }
}
