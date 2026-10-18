<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Observers\Enums\WhatsAppStatus;
use Database\Factories\WhatsappSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappSession extends Model
{
    /** @use HasFactory<WhatsappSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'whatsapp_sessions';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['status', 'phone_number', 'last_connected_at', 'last_error'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppStatus::class,
            'last_connected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
