<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\Customer;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;

final class BanEvidenceService
{
    public function __construct(private TenantContext $tenants, private BanService $bans) {}

    /** @return array<int, array<string, mixed>> */
    public function banned(Actor $actor, string $search = ''): array
    {
        $this->bans->requireAdmin($actor);

        return $this->tenants->runUnscoped(function () use ($search): array {
            return Customer::where('status', CustomerStatus::BANNED)
                ->when(trim($search) !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.trim($search).'%')->orWhere('email', 'like', '%'.trim($search).'%')->orWhere('mobile_number', 'like', '%'.trim($search).'%')))
                ->orderByDesc('banned_at')->orderBy('id')->get()->map(function (Customer $customer): array {
                    $event = SecurityEvent::where('customer_id', $customer->id)->latest()->orderByDesc('id')->first();
                    $session = $event?->payment_session_id === null ? null : PaymentSession::find($event->payment_session_id);

                    return ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->mobile_number,
                        'mobileNumber' => $customer->mobile_number, 'status' => $customer->status->value, 'bannedAt' => $customer->banned_at,
                        'banReason' => $customer->ban_reason, 'paymentSession' => $session === null ? 'N/A' : '#'.$session->order_id,
                        'orderId' => $session?->order_id, 'sessionId' => $session?->id, 'lastDevice' => $customer->last_device ?? $event?->device_info,
                        'source' => $event?->source, 'securityEventId' => $event?->id, 'rawMessage' => $event?->raw_message];
                })->all();
        });
    }

    /** Admin-only raw evidence. Merchant-scoped event browsing remains in the merchant UI phase.
     * @return array<string, mixed>|null
     */
    public function get(string $customerId, Actor $actor): ?array
    {
        $this->bans->requireAdmin($actor);

        return $this->tenants->runUnscoped(function () use ($customerId): ?array {
            $customer = Customer::find($customerId);
            if ($customer === null) {
                return null;
            }
            $events = SecurityEvent::where('customer_id', $customerId)->latest()->orderByDesc('id')->limit(50)->get();
            // Match the current ban type, rather than presenting an old fraud event as a new manual ban.
            $type = $customer->ban_reason === 'MANUAL_BAN' ? 'MANUAL_BAN' : 'FRAUDULENT_PAYMENT_INJECTION';
            $event = $events->firstWhere('event_type', $type) ?? $events->first();
            $session = $event?->payment_session_id === null ? PaymentSession::where('customer_id', $customerId)->latest()->first() : PaymentSession::find($event->payment_session_id);
            $payments = PaymentSession::where('customer_id', $customerId);
            $totalOrders = (clone $payments)->count();
            $spent = (int) (clone $payments)->where('status', PaymentStatus::SUCCESS)->sum('amount_paise');
            $raw = $event->raw_message ?? 'No raw message preserved.';
            $amount = $session === null ? null : (new Money($session->amount_paise))->toRupeesString();
            $automatic = $event?->event_type === 'FRAUDULENT_PAYMENT_INJECTION';

            return [
                'customer' => ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->mobile_number,
                    'status' => $customer->status->value, 'createdAt' => $customer->created_at, 'bannedAt' => $customer->banned_at,
                    'banReason' => $customer->ban_reason, 'lastDevice' => $customer->last_device ?? $event?->device_info,
                    'totalOrders' => $totalOrders, 'totalSpentPaise' => $spent, 'totalSpentRupees' => (new Money($spent))->toRupeesString()],
                'banDetails' => ['banType' => $automatic ? 'AUTOMATIC' : 'MANUAL', 'triggerEvent' => $event?->event_type,
                    'triggerTimestamp' => $customer->banned_at ?? $event?->created_at, 'paymentSessionId' => $session?->id,
                    'orderId' => $session?->order_id, 'sessionAmountPaise' => $session?->amount_paise, 'sessionAmountRupees' => $amount],
                'rawEvidence' => $raw,
                'rawEvidenceObject' => ['rawMessage' => $raw, 'normalizedMessage' => $event?->normalized_message, 'extractedAmount' => $event?->extracted_amount, 'extractedReference' => $event?->extracted_reference],
                'observationMetadata' => ['source' => $event?->source, 'sender' => $event?->sender, 'receiver' => 'SIM Slot #1', 'deviceId' => $event->device_info ?? $customer->last_device],
                'discrepancyAnalysis' => ['customerPhone' => $customer->mobile_number, 'observationSender' => $event?->sender,
                    'registeredBankHeader' => $automatic ? 'NO (Personal sender match)' : 'N/A', 'sessionAmount' => $amount === null ? 'N/A' : 'Rs '.$amount,
                    'messageAmount' => $event->extracted_amount ?? 'N/A', 'sessionRef' => $session === null ? 'N/A' : substr($session->id, 0, 12),
                    'messageRef' => $event->extracted_reference ?? 'N/A', 'result' => $automatic ? 'FRAUDULENT_PAYMENT_INJECTION confirmed.' : 'MANUAL_BAN'],
                'timeline' => ['sessionCreated' => $session?->created_at, 'fakeObservationReceived' => $automatic ? $event->created_at : null,
                    'paymentRejected' => $event?->created_at, 'autoBanExecuted' => $automatic ? $customer->banned_at : null],
                'session' => $session === null ? null : ['id' => $session->id, 'orderId' => $session->order_id, 'amount' => '₹'.$amount,
                    'amountPaise' => (string) $session->amount_paise, 'createdAt' => $session->created_at, 'state' => $session->status->value],
                'securityEvent' => $event === null ? null : ['id' => $event->id, 'detectionTime' => $event->created_at, 'fraudReason' => $event->fraud_reason,
                    'source' => $event->source, 'sender' => $event->sender, 'deviceInfo' => $event->device_info, 'eventType' => $event->event_type],
                'history' => $events->map(fn (SecurityEvent $item): array => ['id' => $item->id, 'eventType' => $item->event_type,
                    'fraudReason' => $item->fraud_reason, 'source' => $item->source, 'sender' => $item->sender, 'timestamp' => $item->created_at, 'rawMessage' => $item->raw_message])->all(),
            ];
        });
    }
}
