<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CustomerStatus $status */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'customers';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['name', 'email', 'mobile_number', 'password_hash', 'password_needs_rehash', 'is_phone_verified', 'status', 'ban_reason', 'last_device', 'banned_at', 'unbanned_at', 'last_login_at'];

    protected $hidden = ['password_hash', 'password_needs_rehash'];

    protected function casts(): array
    {
        return [
            'password_needs_rehash' => 'boolean',
            'is_phone_verified' => 'boolean',
            'status' => CustomerStatus::class,
            'banned_at' => 'immutable_datetime',
            'unbanned_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CustomerSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(CustomerSession::class, 'customer_id');
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'customer_id');
    }

    /** @return HasMany<PaymentSession, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentSession::class, 'customer_id');
    }
}
