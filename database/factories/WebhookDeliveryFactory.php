<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'payment_id' => fn (array $attributes) => PaymentSession::factory()->create(['merchant_id' => $attributes['merchant_id']])->id,
            'event_type' => 'SUCCESS',
            'payload' => '{"event":"SUCCESS"}',
            'endpoint_url' => 'https://merchant.example/webhook',
            'response_status' => null,
            'response_body' => null,
            'attempt_count' => 1,
            'is_successful' => false,
            'next_retry_at' => null,
        ];
    }
}
