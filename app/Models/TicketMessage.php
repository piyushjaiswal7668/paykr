<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Support\Enums\SenderType;
use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ticket_messages';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['ticket_id', 'sender_type', 'sender_id', 'message', 'attachments', 'merchant_id'];

    protected $hidden = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sender_type' => SenderType::class,
            'attachments' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
