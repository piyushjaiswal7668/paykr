<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantTestMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantTestMode> */
class MerchantTestModeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'enabled_at' => null,
            'expires_at' => null,
            'enabled_by' => null,
        ];
    }
}
