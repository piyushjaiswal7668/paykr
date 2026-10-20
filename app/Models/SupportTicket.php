<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Support\Enums\TicketStatus;
use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property TicketStatus $status */
class SupportTicket extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'support_tickets';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['customer_id', 'payment_id', 'subject', 'status', 'merchant_id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<PaymentSession, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'payment_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }
}
