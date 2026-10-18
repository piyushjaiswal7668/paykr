<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\PaymentReceivingAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReceivingAccount extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PaymentReceivingAccountFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_receiving_accounts';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['upi_id', 'payee_name', 'is_merchant_enabled', 'is_active', 'is_slot_busy', 'current_active_session_id', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'is_merchant_enabled' => 'boolean',
            'is_active' => 'boolean',
            'is_slot_busy' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PaymentSession, $this> */
    public function currentActiveSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'current_active_session_id');
    }

    /** @return HasMany<PaymentSession, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentSession::class, 'receiving_account_id');
    }

    /** @return HasMany<PaymentObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(PaymentObservation::class, 'receiving_account_id');
    }
}
