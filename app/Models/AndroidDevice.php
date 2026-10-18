<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\AndroidDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable|null $last_heartbeat_at */
class AndroidDevice extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<AndroidDeviceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'android_devices';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['device_name', 'device_model', 'signing_key', 'is_active', 'last_heartbeat_at', 'last_observation_at', 'merchant_id'];

    protected $hidden = ['signing_key'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'signing_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_heartbeat_at' => 'immutable_datetime',
            'last_observation_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
