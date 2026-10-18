<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BanService
{
    public function __construct(private TenantContext $tenants, private PaymentTransitionService $transitions) {}

    public function requireAdmin(Actor $actor): void
    {
        if ($actor->type !== ActorType::PLATFORM_ADMIN || $actor->id === null || ! User::whereKey($actor->id)->where('is_platform_admin', true)->exists()) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
    }

    public function ban(string $customerId, Actor $actor, string $notes = '', ?string $device = null): Customer
    {
        $this->requireAdmin($actor);

        return $this->tenants->runUnscoped(fn () => DB::transaction(function () use ($customerId, $actor, $notes, $device): Customer {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::NOT_FOUND);
            if ($customer->status === CustomerStatus::BANNED) {
                return $customer;
            }
            $previous = $customer->status->value;
            $customer->update(['status' => CustomerStatus::BANNED, 'ban_reason' => 'MANUAL_BAN', 'banned_at' => now(), 'last_device' => $device ?? $customer->last_device]);
            $this->expireLiveSessions($customer->id, $actor);
            $this->record($customer, $actor, 'CUSTOMER_MANUALLY_BANNED', 'MANUAL_BAN', 'MANUAL_BAN', $previous, $notes);

            return $customer;
        }, 5));
    }

    public function unban(string $customerId, Actor $actor, string $notes = ''): Customer
    {
        $this->requireAdmin($actor);

        return $this->tenants->runUnscoped(fn () => DB::transaction(function () use ($customerId, $actor, $notes): Customer {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::NOT_FOUND);
            if ($customer->status !== CustomerStatus::BANNED) {
                return $customer;
            }
            $previous = $customer->status->value;
            $customer->update(['status' => CustomerStatus::ACTIVE, 'ban_reason' => null, 'unbanned_at' => now()]);
            $this->record($customer, $actor, 'USER_UNBANNED', 'USER_UNBANNED', 'RESTORED_BY_ADMIN', $previous, $notes);

            return $customer;
        }, 5));
    }

    /** Trusted internal operation shared with the fraud pipeline; caller owns the ban transaction. */
    public function expireLiveSessions(string $customerId, Actor $actor): void
    {
        $this->tenants->runUnscoped(function () use ($customerId, $actor): void {
            $payments = PaymentSession::where('customer_id', $customerId)->whereIn('status', [PaymentStatus::INITIATED, PaymentStatus::QUEUED, PaymentStatus::ACTIVE, PaymentStatus::PROCESSING])
                ->orderBy('receiving_account_id')->orderBy('id')->get();
            foreach ($payments as $payment) {
                try {
                    $this->transitions->transition($payment->id, PaymentStatus::EXPIRED, $actor);
                } catch (ValidationException $exception) {
                    if ($exception->errorCode !== ErrorCode::PAYMENT_CLOSED) {
                        throw $exception;
                    }
                }
            }
        });
    }

    private function record(Customer $customer, Actor $actor, string $action, string $event, string $reason, string $previous, string $notes): void
    {
        $details = ['reason' => $reason, 'notes' => $notes, 'previousStatus' => $previous, 'newStatus' => $customer->status->value, 'adminId' => $actor->id];
        AuditLog::create(['merchant_id' => null, 'entity_type' => 'CUSTOMER', 'entity_id' => $customer->id, 'action' => $action,
            'actor_type' => $actor->type->value, 'actor_id' => $actor->id, 'ip_address' => $actor->ip, 'details' => $details]);
        SecurityEvent::create(['merchant_id' => null, 'customer_id' => $customer->id, 'event_type' => $event, 'source' => 'ADMIN',
            'raw_message' => $notes, 'fraud_reason' => $reason, 'metadata' => $details]);
    }
}
