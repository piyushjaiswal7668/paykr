<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Jobs\DeliverWhatsappNotification;
use App\Domain\Observers\Services\WhatsAppSidecar;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Webhooks\Services\WebhookService;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\WhatsappNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentNotifications
{
    public function record(PaymentSession $payment, string $event): void
    {
        $merchant = Merchant::findOrFail($payment->merchant_id);
        if ($payment->is_test || (! $merchant->whatsapp_merchant_enabled && ! $merchant->whatsapp_customer_enabled && ! $merchant->workflow_webhooks_enabled)) {
            return;
        }
        $description = match ($event) {
            'ORDER_RECEIVED' => 'Order received. Payment and booking confirmation are separate.',
            'PAYMENT_VERIFYING' => 'Payment verification is pending. If paid, do not pay again.',
            'PAYMENT_DELAYED' => 'Payment confirmation is delayed or the payment window ended. If already paid, request review; do not pay again.',
            'PAYMENT_CONFIRMED' => $payment->method->value === 'COD' ? 'Cash-on-delivery order placed. Payment is due on delivery.' : 'Payment confirmed. Check booking status separately.',
            'PAYMENT_CANCELLED' => 'Payment session cancelled. If already debited, request merchant review.',
            'PAYMENT_REVIEW_REQUESTED' => 'Payment review requested. The merchant will check the bank evidence.',
            'PAYMENT_REVIEW_REQUIRED' => 'Late payment evidence received. Merchant review is required.',
            'BOOKING_CONFIRMED' => 'Booking confirmed by the merchant.',
            'BOOKING_REJECTED' => 'Booking rejected by the merchant. Contact the merchant about any amount already paid.',
            default => throw new \InvalidArgumentException('Unknown notification event.'),
            'BOOKING_CANCELLED' => 'Booking cancelled by the merchant. This does not mean a refund has been sent.',
        };
        $payload = ['event' => $event, 'eventId' => hash('sha256', $payment->id.':'.$event), 'paymentId' => $payment->id, 'orderId' => $payment->order_id,
            'paymentStatus' => $payment->status->value, 'bookingStatus' => $payment->booking_status, 'bookingVersion' => $payment->booking_version,
            'amount' => (new Money($payment->amount_paise))->toRupeesString(), 'currency' => $payment->currency, 'method' => $payment->method->value,
            'occurredAt' => now()->toIso8601String(), 'message' => $description];
        app(WebhookService::class)->recordWorkflow($payment, $event, $payload);
        $base = $merchant->business_name." — Paykr\nOrder: ".$payment->order_id."\nAmount: ₹".$payload['amount']."\n".$description."\nBooking: ".$payment->booking_status;
        $customerPhone = $payment->customer?->mobile_number;
        foreach (['merchant', 'customer'] as $audience) {
            $phone = $audience === 'merchant' ? $merchant->whatsapp_phone : $payment->whatsapp_phone;
            $enabled = $audience === 'merchant' ? $merchant->whatsapp_merchant_enabled : ($merchant->whatsapp_customer_enabled && $payment->whatsapp_updates_consent && $customerPhone === $phone);
            if (! $enabled || ! is_string($phone) || ! preg_match('/^[6-9][0-9]{9}$/D', $phone)) {
                continue;
            }
            $link = $audience === 'merchant' ? url('/merchant/'.$merchant->slug.'/transactions') : url('/checkout/'.$payment->id);
            $notification = WhatsappNotification::firstOrCreate(['payment_id' => $payment->id, 'event' => $event, 'audience' => $audience], [
                'merchant_id' => $payment->merchant_id, 'recipient' => '91'.$phone, 'message' => $base."\nView securely: ".$link,
                'expected_payment_status' => str_starts_with($event, 'PAYMENT_') ? $payment->status->value : null,
                'expected_booking_status' => str_starts_with($event, 'BOOKING_') ? $payment->booking_status : null,
                'status' => 'PENDING', 'next_attempt_at' => now(),
            ]);
            if ($notification->wasRecentlyCreated) {
                $this->schedule($notification);
            }
        }
    }

    public function deliver(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $notification = WhatsappNotification::whereKey($id)->lockForUpdate()->first();
            if ($notification === null || $notification->status !== 'PENDING' || $notification->next_attempt_at?->isFuture()) {
                return;
            }
            $payment = PaymentSession::findOrFail($notification->payment_id);
            $merchant = Merchant::findOrFail($notification->merchant_id);
            $customer = $notification->audience === 'customer';
            $allowed = $customer ? ($merchant->whatsapp_customer_enabled && $payment->whatsapp_updates_consent && $payment->whatsapp_phone === $payment->customer?->mobile_number && '91'.$payment->whatsapp_phone === $notification->recipient) : ($merchant->whatsapp_merchant_enabled && '91'.$merchant->whatsapp_phone === $notification->recipient);
            if (! $allowed || $merchant->status->value !== 'ACTIVE' || $payment->is_test ||
                ($notification->expected_payment_status !== null && $notification->expected_payment_status !== $payment->status->value) ||
                ($notification->expected_booking_status !== null && $notification->expected_booking_status !== $payment->booking_status)) {
                $notification->update(['status' => 'SKIPPED', 'next_attempt_at' => null]);

                return;
            }
            $sender = app(WhatsAppSidecar::class);
            $attempt = $notification->attempts + 1;
            if (! ($sender->status()['isConnected'] ?? false)) {
                $notification->update(['attempts' => $attempt, 'status' => $attempt >= 5 ? 'FAILED' : 'PENDING', 'next_attempt_at' => $attempt >= 5 ? null : now()->addSeconds(30 * $attempt)]);
                if ($attempt < 5) {
                    $this->schedule($notification);
                }

                return;
            }
            // Persist UNKNOWN before sending: a worker crash must not silently send the same message again.
            // Commit that marker before the remote call (performed below).
            $notification->update(['attempts' => $attempt, 'status' => 'UNKNOWN', 'next_attempt_at' => null]);
            DB::afterCommit(function () use ($notification, $sender): void {
                $result = $sender->send($notification->recipient, $notification->message);
                if (($result['success'] ?? false) === true && ! ($result['simulated'] ?? false)) {
                    $notification->update(['status' => 'SENT', 'provider_id' => $result['id'] ?? null, 'sent_at' => now()]);
                }
            });
        }, 5);
    }

    public function schedule(WhatsappNotification $notification): void
    {
        $job = (new DeliverWhatsappNotification($notification->merchant_id, $notification->id))->delay($notification->next_attempt_at?->ceilSecond());
        DB::afterCommit(static function () use ($job): void {
            try {
                Bus::dispatch($job);
            } catch (Throwable) {
                Log::warning('WhatsApp enqueue failed; pending notification awaits recovery.', ['notification_id' => $job->notificationId]);
            }
        });
    }

    public function recover(): void
    {
        app(TenantContext::class)->runUnscoped(function (): void {
            WhatsappNotification::where('status', 'PENDING')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(100)->get()
                ->each(fn (WhatsappNotification $notification) => $this->schedule($notification));
        });
    }
}
