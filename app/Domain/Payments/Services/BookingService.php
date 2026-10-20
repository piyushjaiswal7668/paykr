<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Notifications\Services\PaymentNotifications;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Models\AuditLog;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class BookingService
{
    /** @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function update(string $paymentId, array $details, string $actorType = 'MERCHANT_API_KEY', ?string $actorId = null): array
    {
        $data = Validator::make($details, ['bookingStatus' => ['required', 'in:CONFIRMED,REJECTED,CANCELLED'], 'bookingVersion' => ['required', 'integer', 'min:0']])->validate();

        return DB::transaction(function () use ($paymentId, $data, $actorType, $actorId): array {
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->booking_status !== $data['bookingStatus']) {
                if ($payment->booking_version !== (int) $data['bookingVersion'] || ! in_array($payment->booking_status, ['PENDING', 'CONFIRMED'], true) ||
                    ($payment->booking_status === 'CONFIRMED' && $data['bookingStatus'] !== 'CANCELLED') ||
                    ($data['bookingStatus'] === 'CONFIRMED' && $payment->status !== PaymentStatus::SUCCESS)) {
                    throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
                }
                $payment->update(['booking_status' => $data['bookingStatus'], 'booking_version' => $payment->booking_version + 1]);
                AuditLog::create(['merchant_id' => $payment->merchant_id, 'entity_type' => 'PAYMENT_SESSION', 'entity_id' => $payment->id, 'action' => 'BOOKING_STATUS_UPDATED', 'actor_type' => $actorType, 'actor_id' => $actorId, 'details' => ['bookingStatus' => $payment->booking_status, 'bookingVersion' => $payment->booking_version]]);
                app(PaymentNotifications::class)->record($payment, 'BOOKING_'.$payment->booking_status);
            }

            return ['paymentId' => $payment->id, 'orderId' => $payment->order_id, 'status' => $payment->status->value, 'bookingStatus' => $payment->booking_status, 'bookingVersion' => $payment->booking_version];
        }, 5);
    }

    /** @return array<string, mixed> */
    public function consent(string $paymentId, bool $enabled, string $actorType): array
    {
        return DB::transaction(function () use ($paymentId, $enabled, $actorType): array {
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->customer === null) {
                throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
            }
            $payment->update(['whatsapp_updates_consent' => $enabled, 'whatsapp_phone' => $enabled ? $payment->customer->mobile_number : null]);
            AuditLog::create(['merchant_id' => $payment->merchant_id, 'entity_type' => 'PAYMENT_SESSION', 'entity_id' => $payment->id,
                'action' => 'WHATSAPP_CONSENT_UPDATED', 'actor_type' => $actorType, 'actor_id' => $actorType === 'CUSTOMER' ? $payment->customer_id : null, 'details' => ['enabled' => $enabled]]);

            return ['paymentId' => $paymentId, 'whatsappUpdatesConsent' => $enabled];
        });
    }
}
