<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AuditLog;
use App\Models\User;

final class PanelAccess
{
    public const ROLES = [
        'view' => ['owner', 'manager', 'cashier', 'developer'],
        'counter' => ['owner', 'manager', 'cashier'],
        'configure' => ['owner', 'manager'],
        'develop' => ['owner', 'developer'],
        'observe' => ['owner', 'manager', 'developer'],
        'team' => ['owner'],
    ];

    public function allows(?User $user, string $merchantId, string $capability): bool
    {
        return $user !== null && $user->merchants()->whereKey($merchantId)->where('status', 'ACTIVE')
            ->wherePivotIn('role', self::ROLES[$capability] ?? [])->exists();
    }

    public function authorize(PanelCommand $command, string $capability): Actor
    {
        if (! $this->allows(User::find($command->userId), $command->merchantId, $capability)) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return new Actor(ActorType::MERCHANT_USER, $command->userId, $command->merchantId, $command->ip);
    }

    public function admin(string $userId, ?string $ip = null): Actor
    {
        if (! User::whereKey($userId)->where('is_platform_admin', true)->exists()) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return new Actor(ActorType::PLATFORM_ADMIN, $userId, ip: $ip);
    }

    /** @param array<string, mixed> $details */
    public function audit(Actor $actor, string $action, string $entity, ?string $id, array $details = []): void
    {
        AuditLog::create(['merchant_id' => $actor->merchantId, 'entity_type' => $entity, 'entity_id' => $id ?? '00000000-0000-0000-0000-000000000000',
            'action' => $action, 'actor_type' => $actor->type->value, 'actor_id' => $actor->id,
            'ip_address' => $actor->ip, 'details' => $details]);
    }
}
