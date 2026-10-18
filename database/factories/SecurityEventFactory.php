<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SecurityEvent> */
class SecurityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'customer_id' => null,
            'payment_session_id' => null,
            'event_type' => 'TEST_EVENT',
            'source' => null,
            'sender' => null,
            'device_info' => null,
            'normalized_message' => null,
            'extracted_amount' => null,
            'extracted_reference' => null,
            'raw_message' => 'Test evidence',
            'fraud_reason' => 'Test reason',
            'parser_results' => null,
            'verification_results' => null,
            'metadata' => null,
        ];
    }
}
