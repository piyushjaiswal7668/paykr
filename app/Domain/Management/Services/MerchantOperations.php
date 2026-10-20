<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Notifications\Services\PaymentNotifications;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\BookingService;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Vpa;
use App\Models\Merchant;
use App\Models\MerchantCustomerBlock;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\WhatsappNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MerchantOperations
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants, private PaymentTransitionService $transitions) {}

    public function execute(PanelCommand $command): void
    {
        $actor = $this->access->authorize($command, str_starts_with($command->operation, 'counter-') ? 'counter' : 'configure');
        $this->tenants->run($command->merchantId, fn () => DB::transaction(function () use ($command, $actor): void {
            Merchant::whereKey($command->merchantId)->lockForUpdate()->firstOrFail();
            match ($command->operation) {
                'notification-settings' => $this->notificationSettings($command),
                'notification-retry' => $this->retryNotification($command),
                'booking-update' => app(BookingService::class)->update((string) $command->recordId, $command->data, 'MERCHANT_USER', $command->userId),
                'counter-confirm', 'counter-decline' => $this->counter($command, $actor),
                'clear-queue' => $this->clearQueue($actor),
                'account-create', 'account-activate', 'account-deactivate', 'account-delete' => $this->account($command),
                'customer-block', 'customer-unblock' => $this->block($command, $actor),
                'ticket-resolve' => SupportTicket::findOrFail($command->recordId)->update(['status' => 'RESOLVED']),
                default => throw new ValidationException(ErrorCode::NOT_FOUND),
            };
            $this->access->audit($actor, match ($command->operation) {
                'counter-confirm' => 'COUNTER_MANUAL_PAYMENT_CONFIRMED', 'counter-decline' => 'COUNTER_MANUAL_PAYMENT_DECLINED', default => strtoupper(str_replace('-', '_', $command->operation))
            }, 'MERCHANT_OPERATION', $command->recordId ?? $command->merchantId,
                isset($command->data['reason']) ? ['reason' => $command->data['reason']] : []);
        }, 5));
    }

    private function notificationSettings(PanelCommand $command): void
    {
        $data = Validator::make($command->data, [
            'whatsapp_merchant_enabled' => ['required', 'boolean'],
            'whatsapp_customer_enabled' => ['required', 'boolean'],
            'workflow_webhooks_enabled' => ['required', 'boolean'],
            'whatsapp_phone' => ['nullable', 'required_if:whatsapp_merchant_enabled,true', 'regex:/^[6-9][0-9]{9}$/D'],
        ])->validate();
        Merchant::findOrFail($command->merchantId)->update($data);
    }

    private function retryNotification(PanelCommand $command): void
    {
        $notification = WhatsappNotification::whereKey($command->recordId)->lockForUpdate()->firstOrFail();
        if (! in_array($notification->status, ['FAILED', 'UNKNOWN'], true) || ($notification->status === 'UNKNOWN' && ($notification->updated_at?->gt(now()->subMinutes(5)) ?? true))) {
            throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
        }
        $notification->update(['status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now()]);
        app(PaymentNotifications::class)->schedule($notification);
    }

    private function counter(PanelCommand $command, Actor $actor): void
    {
        $payment = PaymentSession::whereKey($command->recordId)->lockForUpdate()->firstOrFail();
        if ($payment->status->isTerminal()) {
            throw new ValidationException(ErrorCode::PAYMENT_CLOSED);
        }
        $payment = app(PaymentTimingService::class)->expireIfDue($payment->id);
        if ($payment->status !== PaymentStatus::ACTIVE || ! in_array($payment->method->value, ['CASH_COUNTER', 'CARD_COUNTER'], true)) {
            throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
        }
        if ($command->operation === 'counter-decline') {
            Validator::make($command->data, ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
        } else {
            $payment->update(['verification_level' => 1, 'verification_tag' => 'COUNTER_CONFIRMED']);
        }
        $this->transitions->transition($payment->id, $command->operation === 'counter-confirm' ? PaymentStatus::SUCCESS : PaymentStatus::FAILED, $actor);
    }

    private function clearQueue(Actor $actor): void
    {
        PaymentReceivingAccount::orderBy('id')->lockForUpdate()->get();
        foreach (PaymentSession::where('status', PaymentStatus::QUEUED)->orderByDesc('queue_position')->lockForUpdate()->get() as $payment) {
            $this->transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        }
    }

    private function account(PanelCommand $command): void
    {
        $accounts = PaymentReceivingAccount::orderBy('id')->lockForUpdate()->get();
        if ($command->operation === 'account-create') {
            $data = Validator::make($command->data, ['upi_id' => ['required', 'string', 'max:100'], 'payee_name' => ['required', 'string', 'max:100'], 'is_merchant_enabled' => ['required', 'boolean']])->validate();
            new Vpa($data['upi_id']);
            PaymentReceivingAccount::create($data + ['is_active' => false]);

            return;
        }
        $account = $accounts->firstWhere('id', $command->recordId) ?? throw new ValidationException(ErrorCode::NOT_FOUND);
        if ($accounts->contains('is_slot_busy', true) || PaymentSession::whereIn('status', ['QUEUED', 'ACTIVE', 'PROCESSING'])->whereNotNull('receiving_account_id')->exists()) {
            throw new ValidationException(ErrorCode::RECEIVING_ACCOUNT_BUSY);
        }
        if ($command->operation === 'account-delete') {
            if ($account->payments()->exists() || $account->observations()->exists()) {
                throw new ValidationException(ErrorCode::RECEIVING_ACCOUNT_BUSY);
            }
            $account->delete();
        } else {
            if ($command->operation === 'account-activate') {
                PaymentReceivingAccount::where('is_active', true)->update(['is_active' => false]);
            }
            $account->update(['is_active' => $command->operation === 'account-activate']);
        }
    }

    private function block(PanelCommand $command, Actor $actor): void
    {
        if (! PaymentSession::where('customer_id', $command->recordId)->exists()) {
            throw new ValidationException(ErrorCode::NOT_FOUND);
        }
        if ($command->operation === 'customer-unblock') {
            MerchantCustomerBlock::where('customer_id', $command->recordId)->delete();

            return;
        }
        $data = Validator::make($command->data, ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
        MerchantCustomerBlock::updateOrCreate(['customer_id' => $command->recordId], $data + ['blocked_by' => $actor->id]);
        foreach (PaymentSession::where('customer_id', $command->recordId)->whereIn('status', ['INITIATED', 'QUEUED', 'ACTIVE', 'PROCESSING'])->orderBy('receiving_account_id')->get() as $payment) {
            $this->transitions->transition($payment->id, PaymentStatus::EXPIRED, $actor);
        }
    }
}
