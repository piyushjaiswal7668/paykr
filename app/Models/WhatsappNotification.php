<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\WhatsappNotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 */
class WhatsappNotification extends Model
{
    use BelongsToMerchant, HasUuids;

    /** @use HasFactory<WhatsappNotificationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['recipient', 'message'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'message' => 'encrypted', 'attempts' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
