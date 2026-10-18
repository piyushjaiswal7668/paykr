<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantPaymentSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantPaymentSettings> */
class MerchantPaymentSettingsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'selling_mode' => 'ONLINE',
            'cod_enabled' => false,
            'cash_enabled' => false,
            'card_enabled' => false,
            'enabled_payment_methods' => ['UPI'],
        ];
    }
}
