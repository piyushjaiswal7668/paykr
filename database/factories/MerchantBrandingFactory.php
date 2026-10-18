<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantBranding> */
class MerchantBrandingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'app_name' => 'Paykr',
            'primary_color' => '#2563eb',
            'header_theme' => null,
            'logo_url' => null,
            'sidebar_notice' => null,
            'subbanner_text' => null,
            'trust_badge_text' => null,
            'checkout_notice' => null,
            'footer_guarantee' => null,
            'counter_instructions' => null,
            'card_counter_instructions' => null,
            'terms_url' => null,
            'privacy_policy_url' => null,
            'hide_trust_badge' => false,
            'hide_subbanner' => false,
            'hide_powered_by' => false,
            'hide_time_saving_banner' => false,
            'support_emails' => [],
            'support_mobiles' => [],
        ];
    }
}
