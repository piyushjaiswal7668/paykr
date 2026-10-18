<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Merchants\Enums\ApiKeyMode;
use App\Domain\Merchants\Enums\ApiKeyType;
use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property ApiKeyType $type
 * @property ApiKeyMode $mode
 */
class MerchantApiKey extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantApiKeyFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_api_keys';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['type', 'mode', 'prefix', 'key_hash', 'last_used_at', 'revoked_at', 'merchant_id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'type' => ApiKeyType::class,
            'mode' => ApiKeyMode::class,
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
