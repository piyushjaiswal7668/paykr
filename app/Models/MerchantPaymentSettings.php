<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Merchants\Enums\SellingMode;
use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantPaymentSettingsFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property SellingMode $selling_mode */
class MerchantPaymentSettings extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantPaymentSettingsFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_payment_settings';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['selling_mode', 'cod_enabled', 'cash_enabled', 'card_enabled', 'enabled_payment_methods', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'selling_mode' => SellingMode::class,
            'cod_enabled' => 'boolean',
            'cash_enabled' => 'boolean',
            'card_enabled' => 'boolean',
            'enabled_payment_methods' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
