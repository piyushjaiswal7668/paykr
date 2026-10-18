<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantBrandingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantBranding extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantBrandingFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_branding';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['app_name', 'primary_color', 'header_theme', 'logo_url', 'sidebar_notice', 'subbanner_text', 'trust_badge_text', 'checkout_notice', 'footer_guarantee', 'counter_instructions', 'card_counter_instructions', 'terms_url', 'privacy_policy_url', 'hide_trust_badge', 'hide_subbanner', 'hide_powered_by', 'hide_time_saving_banner', 'support_emails', 'support_mobiles', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'hide_trust_badge' => 'boolean',
            'hide_subbanner' => 'boolean',
            'hide_powered_by' => 'boolean',
            'hide_time_saving_banner' => 'boolean',
            'support_emails' => 'array',
            'support_mobiles' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
