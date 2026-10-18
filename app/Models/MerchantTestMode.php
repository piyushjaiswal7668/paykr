<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantTestModeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantTestMode extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantTestModeFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_test_modes';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['enabled_at', 'expires_at', 'enabled_by', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
