<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\ObserverPairingTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $expires_at */
class ObserverPairingToken extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<ObserverPairingTokenFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'observer_pairing_tokens';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['token_hash', 'created_by', 'expires_at', 'consumed_at', 'consumed_by_device_id', 'merchant_id'];

    protected $hidden = ['token_hash'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<AndroidDevice, $this> */
    public function consumedByDevice(): BelongsTo
    {
        return $this->belongsTo(AndroidDevice::class, 'consumed_by_device_id');
    }
}
