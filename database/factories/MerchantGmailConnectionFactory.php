<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantGmailConnection> */
class MerchantGmailConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'client_id' => null,
            'client_secret' => null,
            'refresh_token' => null,
            'access_token' => null,
            'legacy_api_key' => null,
            'token_expires_at' => null,
            'last_tested_at' => null,
            'last_successful_fetch' => null,
            'last_observation_at' => null,
            'connected_email' => null,
            'last_history_id' => null,
            'status' => 'DISCONNECTED',
        ];
    }
}
