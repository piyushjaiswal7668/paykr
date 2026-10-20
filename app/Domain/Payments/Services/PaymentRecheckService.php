<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Notifications\Services\PaymentNotifications;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Verification\Services\AttributionEngine;
use App\Models\PaymentObservation;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

final class PaymentRecheckService
{
    /** @param array<string, string|null> $details
     * @return array<string, mixed>
     */
    public function execute(string $paymentId, bool $requestReview = false, array $details = []): array
    {
        $payment = PaymentSession::findOrFail($paymentId);
        if ($payment->method !== PaymentMethod::UPI || $payment->customer_id === null || in_array($payment->status, [PaymentStatus::INITIATED, PaymentStatus::QUEUED], true)) {
            throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
        }
        // Reuse stored, validated evidence. Never ingest a customer's claimed UTR as evidence.
        app(PaymentTimingService::class)->expireIfDue($paymentId);
        app(AttributionEngine::class)->finalizeLevel1($paymentId);

        return DB::transaction(function () use ($paymentId, $requestReview, $details): array {
            // Serialize review creation against other requests and payment transitions.
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $subject = '[PAYMENT REVIEW] Order #'.$payment->order_id;
            $ticket = SupportTicket::where('payment_id', $paymentId)->whereIn('subject', [$subject, '[LATE PAYMENT DETECTED] Order #'.$payment->order_id])->oldest()->first();
            $confirmed = $payment->status === PaymentStatus::SUCCESS;
            $hasEvidence = PaymentObservation::where('attributed_payment_id', $paymentId)->whereIn('attribution_status', ['ATTRIBUTED', 'LATE_UNMATCHED'])->exists();
            $canRequestReview = ! $confirmed && $ticket === null && (! $hasEvidence || $payment->status->isTerminal());
            if ($requestReview && $canRequestReview) {
                $ticket = SupportTicket::create(['merchant_id' => $payment->merchant_id, 'customer_id' => $payment->customer_id, 'payment_id' => $paymentId, 'subject' => $subject, 'status' => 'OPEN']);
                app(PaymentNotifications::class)->record($payment, 'PAYMENT_REVIEW_REQUESTED');
                TicketMessage::create(['merchant_id' => $payment->merchant_id, 'ticket_id' => $ticket->id, 'sender_type' => 'CUSTOMER', 'sender_id' => $payment->customer_id,
                    'message' => 'Unverified payment review request. Reference: '.($details['reference'] ?? '(not supplied)')."\n".($details['note'] ?? '')."\nVerify bank evidence independently; this request does not confirm payment."]);
            }
            $verification = $confirmed ? 'CONFIRMED' : ($ticket !== null ? 'REVIEW_REQUESTED' : ($hasEvidence ? ($payment->status->isTerminal() ? 'REVIEW_REQUIRED' : 'VERIFYING') : 'AWAITING_EVIDENCE'));

            return ['paymentId' => $paymentId, 'orderId' => $payment->order_id, 'status' => $payment->status->value,
                'verificationStatus' => $verification, 'canRequestReview' => $canRequestReview && $ticket === null,
                'reviewId' => $ticket?->id, 'reviewStatus' => $ticket?->status->value,
                'message' => match ($verification) {
                    'CONFIRMED' => 'Payment confirmed.',
                    'REVIEW_REQUESTED' => 'Your payment review request is recorded. Check its review status; do not pay again.',
                    'REVIEW_REQUIRED' => 'Payment evidence requires merchant review. Do not pay again.',
                    'VERIFYING' => 'Payment evidence is being verified. Do not pay again.',
                    default => 'Awaiting bank evidence. If you already paid, do not pay again.',
                }];
        }, 5);
    }
}
