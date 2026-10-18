<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Devices\Enums\DeviceStatus;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property DeviceStatus $status */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'devices';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['legacy_id', 'customer_id', 'public_key', 'fingerprint_hash', 'user_agent', 'platform', 'browser', 'timezone', 'language', 'screen_signature', 'device_token_hash', 'status', 'last_seen_at'];

    protected $hidden = ['device_token_hash'];

    public const UPDATED_AT = null;

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeByPublicId(Builder $query, string $id): Builder
    {
        return $query->where(fn ($q) => $q->where('id', $id)->orWhere('legacy_id', $id));
    }

    public function publicId(): string
    {
        return $this->legacy_id ?? $this->id;
    }

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
