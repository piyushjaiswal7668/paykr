<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $event_type
 * @property bool $is_successful
 * @property int $attempt_count
 * @property CarbonImmutable|null $next_retry_at
 */
class WebhookDelivery extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'webhook_deliveries';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['payment_id', 'event_type', 'payload', 'endpoint_url', 'response_status', 'response_body', 'attempt_count', 'is_successful', 'next_retry_at', 'merchant_id'];

    protected $hidden = ['payload', 'response_body'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'attempt_count' => 'integer',
            'is_successful' => 'boolean',
            'next_retry_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PaymentSession, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'payment_id');
    }
}
