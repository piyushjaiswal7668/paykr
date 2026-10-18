<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentObservation> */
class PaymentObservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'receiving_account_id' => fn (array $attributes) => PaymentReceivingAccount::factory()->create(['merchant_id' => $attributes['merchant_id']])->id,
            'source' => 'SMS_GATEWAY',
            'amount_paise' => 100,
            'observed_at' => now(),
            'ingested_at' => now(),
            'sender_vpa' => null,
            'bank_reference_number' => null,
            'fingerprint_hash' => hash('sha256', fake()->uuid()),
            'raw_payload' => [],
            'attribution_status' => 'UNATTRIBUTED',
            'attributed_payment_id' => null,
        ];
    }
}
