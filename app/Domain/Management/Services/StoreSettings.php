<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantBranding;
use App\Models\MerchantPaymentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class StoreSettings
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants) {}

    public function save(PanelCommand $command): void
    {
        $actor = $this->access->authorize($command, 'configure');
        $this->tenants->run($command->merchantId, fn () => DB::transaction(function () use ($command, $actor): void {
            Merchant::whereKey($command->merchantId)->lockForUpdate()->firstOrFail();
            if ($command->operation === 'branding') {
                $data = Validator::make($command->data, self::brandingRules())->validate();
                if (isset($data['business_name'])) {
                    Merchant::findOrFail($command->merchantId)->update(['business_name' => $data['business_name']]);
                }
                unset($data['business_name']);
                MerchantBranding::updateOrCreate(['merchant_id' => $command->merchantId], $data);
            } elseif ($command->operation === 'methods') {
                $data = Validator::make($command->data, ['selling_mode' => ['required', 'in:ONLINE,OFFLINE'], 'cod_enabled' => ['required', 'boolean'], 'cash_enabled' => ['required', 'boolean'], 'card_enabled' => ['required', 'boolean'], 'counter_instructions' => ['nullable', 'string', 'max:2000'], 'card_counter_instructions' => ['nullable', 'string', 'max:2000']])->validate();
                MerchantBranding::updateOrCreate(['merchant_id' => $command->merchantId], array_intersect_key($data, array_flip(['counter_instructions', 'card_counter_instructions'])));
                $data['enabled_payment_methods'] = ['UPI'];
                foreach (['COD' => 'cod_enabled', 'CASH_COUNTER' => 'cash_enabled', 'CARD_COUNTER' => 'card_enabled'] as $method => $field) {
                    if ($data[$field] && (($method === 'COD') === ($data['selling_mode'] === 'ONLINE'))) {
                        $data['enabled_payment_methods'][] = $method;
                    }
                }
                MerchantPaymentSettings::updateOrCreate(['merchant_id' => $command->merchantId], array_diff_key($data, array_flip(['counter_instructions', 'card_counter_instructions'])));
            } else {
                throw new ValidationException(ErrorCode::NOT_FOUND);
            }
            $this->access->audit($actor, 'STORE_'.strtoupper($command->operation).'_UPDATED', 'MERCHANT', $command->merchantId);
        }));
    }

    /** @return array<string, array<int, string>> */
    public static function brandingRules(): array
    {
        $rules = ['business_name' => ['sometimes', 'required', 'string', 'max:100'], 'app_name' => ['required', 'string', 'max:80'], 'primary_color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/D'], 'header_theme' => ['required', 'in:gradient,solid,dark']];
        foreach (['sidebar_notice', 'subbanner_text', 'trust_badge_text', 'checkout_notice', 'footer_guarantee', 'counter_instructions', 'card_counter_instructions'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:2000'];
        }
        foreach (['logo_url', 'terms_url', 'privacy_policy_url'] as $field) {
            $rules[$field] = ['nullable', 'url:http,https', 'max:2048'];
        }
        foreach (['hide_trust_badge', 'hide_subbanner', 'hide_powered_by', 'hide_time_saving_banner'] as $field) {
            $rules[$field] = ['sometimes', 'boolean'];
        }
        foreach (['support_emails', 'support_mobiles'] as $field) {
            $rules[$field] = ['sometimes', 'array', 'max:10'];
        }
        $rules['support_emails.*'] = ['email', 'max:254'];
        $rules['support_mobiles.*'] = ['string', 'regex:/^\+?[0-9]{7,15}$/D'];

        return $rules;
    }
}
