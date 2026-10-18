<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantCustomerBlockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantCustomerBlock extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantCustomerBlockFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_customer_blocks';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['customer_id', 'reason', 'blocked_by', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }
}
