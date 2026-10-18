<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantApiKey> */
class MerchantApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'type' => 'secret',
            'mode' => 'test',
            'prefix' => 'sk_test_'.fake()->lexify('????'),
            'key_hash' => hash('sha256', fake()->uuid()),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}
