<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSession extends Model
{
    /** @use HasFactory<CustomerSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'customer_sessions';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['customer_id', 'token_hash', 'expires_at', 'is_active', 'ip_address', 'user_agent'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
