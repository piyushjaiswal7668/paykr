<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $metadata
 * @property list<string>|null $verification_sources
 * @property int $verification_level
 * @property CarbonImmutable|null $verification_grace_expires_at
 * @property CarbonImmutable $created_at
 * @property PaymentStatus $status
 * @property PaymentMethod $method
 * @property int $queue_position
 * @property CarbonImmutable|null $slot_acquired_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $processing_expires_at
 * @property CarbonImmutable|null $completed_at
 */
class PaymentSession extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PaymentSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_sessions';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['customer_id', 'receiving_account_id', 'device_id', 'order_id', 'amount_paise', 'currency', 'method', 'status', 'queue_position', 'slot_acquired_at', 'expires_at', 'processing_started_at', 'processing_expires_at', 'completed_at', 'return_url', 'attributed_observation_id', 'idempotency_key', 'verification_grace_expires_at', 'verification_level', 'verification_sources', 'verification_tag', 'metadata', 'is_test', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'queue_position' => 'integer',
            'slot_acquired_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processing_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'verification_grace_expires_at' => 'immutable_datetime',
            'verification_level' => 'integer',
            'verification_sources' => 'array',
            'metadata' => 'array',
            'is_test' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<PaymentReceivingAccount, $this> */
    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentReceivingAccount::class, 'receiving_account_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** @return BelongsTo<PaymentObservation, $this> */
    public function attributedObservation(): BelongsTo
    {
        return $this->belongsTo(PaymentObservation::class, 'attributed_observation_id');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'payment_id');
    }

    /** @return HasMany<PaymentObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(PaymentObservation::class, 'attributed_payment_id');
    }
}
