<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Observations\Enums\ObserverSource;
use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentObservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $raw_payload
 * @property ObserverSource $source
 * @property AttributionStatus $attribution_status
 * @property int $amount_paise
 * @property CarbonImmutable $observed_at
 */
class PaymentObservation extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PaymentObservationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_observations';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['receiving_account_id', 'source', 'amount_paise', 'observed_at', 'ingested_at', 'sender_vpa', 'bank_reference_number', 'fingerprint_hash', 'raw_payload', 'attribution_status', 'attributed_payment_id', 'merchant_id'];

    protected $hidden = ['raw_payload'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source' => ObserverSource::class,
            'amount_paise' => 'integer',
            'observed_at' => 'immutable_datetime',
            'ingested_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'attribution_status' => AttributionStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PaymentReceivingAccount, $this> */
    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentReceivingAccount::class, 'receiving_account_id');
    }

    /** @return BelongsTo<PaymentSession, $this> */
    public function attributedPayment(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'attributed_payment_id');
    }
}
