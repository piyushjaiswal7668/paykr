<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Merchants\Enums\SellingMode;
use App\Domain\Observers\Enums\ConnectionStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;
use App\Models\MerchantTestMode;
use Illuminate\Support\Str;

final class ImportMerchantSettings
{
    /** @param array<string|int, mixed> $branding */
    public function import(Merchant $merchant, array $branding): void
    {
        $public = [];
        foreach ((new MerchantBranding)->getFillable() as $field) {
            $key = Str::camel($field);
            if ($field !== 'merchant_id' && array_key_exists($key, $branding)) {
                $public[$field] = $branding[$key];
            }
        }
        MerchantBranding::create(['merchant_id' => $merchant->id, ...$public]);
        $mode = SellingMode::from($branding['sellingMode'] ?? SellingMode::ONLINE->value);
        $enabled = $branding['enabledPaymentMethods'] ?? [PaymentMethod::UPI->value];
        $cod = (bool) ($branding['codEnabled'] ?? in_array(PaymentMethod::COD->value, $enabled, true));
        $cash = (bool) ($branding['cashEnabled'] ?? in_array(PaymentMethod::CASH_COUNTER->value, $enabled, true));
        $card = (bool) ($branding['cardEnabled'] ?? in_array(PaymentMethod::CARD_COUNTER->value, $enabled, true));
        $methods = [PaymentMethod::UPI->value];
        if ($mode === SellingMode::ONLINE && $cod) {
            $methods[] = PaymentMethod::COD->value;
        }
        if ($mode === SellingMode::OFFLINE && $cash) {
            $methods[] = PaymentMethod::CASH_COUNTER->value;
        }
        if ($mode === SellingMode::OFFLINE && $card) {
            $methods[] = PaymentMethod::CARD_COUNTER->value;
        }
        MerchantPaymentSettings::create(['merchant_id' => $merchant->id, 'selling_mode' => $mode, 'cod_enabled' => $cod, 'cash_enabled' => $cash, 'card_enabled' => $card, 'enabled_payment_methods' => $methods]);
        if (isset($branding['gmailConfig'])) {
            $gmail = $branding['gmailConfig'];
            $attributes = ['merchant_id' => $merchant->id, 'status' => ! empty($gmail['refreshToken']) ? ConnectionStatus::CONFIGURED : ConnectionStatus::DISCONNECTED];
            foreach (['client_id', 'client_secret', 'refresh_token', 'access_token', 'connected_email', 'last_history_id'] as $field) {
                $attributes[$field] = $gmail[Str::camel($field)] ?? null;
            }
            $attributes['legacy_api_key'] = $gmail['apiKey'] ?? null;
            foreach (['token_expires_at' => 'tokenExpiresAt', 'last_tested_at' => 'lastTestedAt', 'last_successful_fetch' => 'lastSuccessfulFetch', 'last_observation_at' => 'lastObservationSent'] as $field => $key) {
                $attributes[$field] = ! empty($gmail[$key]) ? LegacyRowMapper::date($gmail[$key]) : null;
            }
            MerchantGmailConnection::create($attributes);
        }
        if (! empty($branding['testModeEnabledAt']) && ! empty($branding['testModeUntil'])) {
            MerchantTestMode::create(['merchant_id' => $merchant->id, 'enabled_at' => LegacyRowMapper::date($branding['testModeEnabledAt']), 'expires_at' => LegacyRowMapper::date($branding['testModeUntil'])]);
        }
    }
}
