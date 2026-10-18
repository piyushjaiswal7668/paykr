<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Management\Services\PanelAuditContext;
use App\Domain\Shared\Enums\AuditActorType;
use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $details */
class AuditLog extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'audit_logs';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['entity_type', 'entity_id', 'action', 'actor_type', 'actor_id', 'details', 'ip_address', 'merchant_id'];

    protected $hidden = [];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $context = app(PanelAuditContext::class);
            if ($context->actor !== null) {
                $log->actor_type = $context->actor->type->value;
                $log->actor_id = $context->actor->id;
                $log->ip_address = $context->actor->ip;
                if ($context->impersonatedUserId !== null) {
                    $log->details = ($log->details ?? []) + ['impersonatedUserId' => $context->impersonatedUserId];
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
