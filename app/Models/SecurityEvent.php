<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'security_events';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['customer_id', 'payment_session_id', 'event_type', 'source', 'sender', 'device_info', 'normalized_message', 'extracted_amount', 'extracted_reference', 'raw_message', 'fraud_reason', 'parser_results', 'verification_results', 'metadata', 'merchant_id'];

    protected $hidden = ['raw_message'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'parser_results' => 'array',
            'verification_results' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<PaymentSession, $this> */
    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'payment_session_id');
    }
}
