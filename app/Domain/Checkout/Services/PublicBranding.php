<?php

declare(strict_types=1);

namespace App\Domain\Checkout\Services;

use App\Domain\Management\Services\PlatformSettings;
use App\Domain\Merchants\Enums\SellingMode;
use App\Models\MerchantBranding;
use App\Models\MerchantPaymentSettings;

final class PublicBranding
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        $branding = MerchantBranding::first();
        $settings = MerchantPaymentSettings::first();
        $fields = ['appName' => ['app_name', PlatformSettings::value('app_name', config('paykr.defaults.app_name'))], 'primaryColor' => ['primary_color', PlatformSettings::value('primary_color', config('paykr.defaults.primary_color'))], 'headerTheme' => ['header_theme', 'gradient'], 'logoUrl' => ['logo_url', null], 'sidebarNotice' => ['sidebar_notice', 'Fast & Secure 256-Bit Encrypted Payments'], 'subbannerText' => ['subbanner_text', ''], 'trustBadgeText' => ['trust_badge_text', ''], 'checkoutNotice' => ['checkout_notice', ''], 'footerGuarantee' => ['footer_guarantee', 'Secured by Paykr 256-Bit Encryption'], 'counterInstructions' => ['counter_instructions', 'Please visit the billing counter and present this screen to the cashier.'], 'cardCounterInstructions' => ['card_counter_instructions', 'Please swipe, insert, or tap your credit or debit card on the store POS terminal at the billing desk.'], 'termsUrl' => ['terms_url', null], 'privacyPolicyUrl' => ['privacy_policy_url', null], 'hideTrustBadge' => ['hide_trust_badge', false], 'hideSubbanner' => ['hide_subbanner', false], 'hidePoweredBy' => ['hide_powered_by', false], 'hideTimeSavingBanner' => ['hide_time_saving_banner', false], 'supportEmails' => ['support_emails', []], 'supportMobiles' => ['support_mobiles', []]];
        $result = [];
        foreach ($fields as $public => [$column, $default]) {
            $result[$public] = $branding?->getAttribute($column) ?? $default;
        }
        $result['sellingMode'] = $settings?->selling_mode->value ?? 'ONLINE';
        $methods = ['UPI'];
        if ($settings?->selling_mode === SellingMode::ONLINE && $settings->cod_enabled) {
            $methods[] = 'COD';
        }
        if ($settings?->selling_mode === SellingMode::OFFLINE && $settings->cash_enabled) {
            $methods[] = 'CASH_COUNTER';
        }
        if ($settings?->selling_mode === SellingMode::OFFLINE && $settings->card_enabled) {
            $methods[] = 'CARD_COUNTER';
        }
        $result['enabledPaymentMethods'] = $methods;

        return $result;
    }
}
