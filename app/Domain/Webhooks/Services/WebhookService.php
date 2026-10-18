<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WebhookService
{
    public function __construct(private WebhookUrlPolicy $urls) {}

    /** Called inside the terminal transition; locks also make explicit recovery calls idempotent. */
    public function recordTerminal(string $paymentId): ?WebhookDelivery
    {
        return DB::transaction(function () use ($paymentId): ?WebhookDelivery {
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            }
            if (! $payment->status->isTerminal()) {
                throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $existing = WebhookDelivery::where('payment_id', $paymentId)->where('event_type', $payment->status)->first();
            if ($existing !== null) {
                return $existing;
            }
            $merchant = Merchant::findOrFail($payment->merchant_id);
            if (! $merchant->webhook_url) {
                return null;
            }
            $payload = [
                'event' => $payment->status->value,
                'paymentId' => $payment->id,
                'orderId' => $payment->order_id,
                'amount' => (new Money($payment->amount_paise))->toRupeesString(),
                'amountPaise' => $payment->amount_paise,
                'currency' => $payment->currency,
                'method' => $payment->method->value,
                'utr' => $payment->attributedObservation?->bank_reference_number,
                'completedAt' => ($payment->completed_at ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'metadata' => $payment->metadata === [] ? (object) [] : ($payment->metadata ?? (object) []),
            ];
            $delivery = WebhookDelivery::create([
                'merchant_id' => $payment->merchant_id, 'payment_id' => $payment->id, 'event_type' => $payment->status,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS),
                'endpoint_url' => $merchant->webhook_url, 'attempt_count' => 0, 'is_successful' => false, 'next_retry_at' => now(),
            ]);
            $this->schedule($delivery);

            return $delivery;
        });
    }

    public function deliver(string $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            // Serialize duplicate jobs for this delivery. Stable ID enables merchant-side deduplication
            // if a worker dies after the HTTP response but before committing its result.
            $delivery = WebhookDelivery::whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->is_successful || $delivery->attempt_count >= $this->maxAttempts()
                || ($delivery->next_retry_at !== null && $delivery->next_retry_at->isFuture())) {
                return;
            }
            $merchant = Merchant::findOrFail($delivery->merchant_id);
            $status = null;
            $body = '';
            $successful = false;
            try {
                $options = $this->urls->requestOptions($delivery->endpoint_url);
                if (! is_string($merchant->webhook_secret) || $merchant->webhook_secret === '') {
                    throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
                }
                $response = Http::withOptions($options)->timeout(10)->connectTimeout(5)
                    ->withHeaders([
                        'X-Gateway-Event' => $delivery->event_type->value,
                        'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $delivery->payload, $merchant->webhook_secret),
                        'X-Gateway-Delivery-Id' => $delivery->id,
                        'X-Gateway-Timestamp' => (string) now()->getTimestampMs(),
                    ])->withBody($delivery->payload, 'application/json')->post($delivery->endpoint_url);
                $status = $response->status();
                $body = mb_substr(mb_convert_encoding($response->body(), 'UTF-8', 'UTF-8'), 0, 1000);
                $successful = $response->successful();
            } catch (ValidationException) {
                $body = 'Webhook endpoint or signing configuration is not allowed.';
            } catch (ConnectionException) {
                // Do not store exception strings containing request headers or signed payloads.
                $body = 'Webhook connection failed or timed out.';
            }
            $attempt = $delivery->attempt_count + 1;
            $delays = config('paykr.webhooks.backoff_seconds');
            $retryAt = $successful || $attempt >= $this->maxAttempts() ? null : now()->addSeconds($delays[min($attempt - 1, count($delays) - 1)]);
            $delivery->update([
                'attempt_count' => $attempt, 'is_successful' => $successful, 'response_status' => $status,
                'response_body' => $body, 'next_retry_at' => $retryAt,
            ]);
            if ($retryAt !== null) {
                $this->schedule($delivery);
            }
        });
    }

    /** Redis is a delivery mechanism; the persisted due row is the source of truth for recovery. */
    public function schedule(WebhookDelivery $delivery): void
    {
        // Redis availability uses whole seconds. Round up so a millisecond deadline cannot
        // arrive early, hit the due-time guard, and silently consume its only scheduled job.
        $job = (new DeliverWebhook($delivery->merchant_id, $delivery->id))->delay($delivery->next_retry_at?->ceilSecond());
        DB::afterCommit(static function () use ($job): void {
            try {
                Bus::dispatch($job);
            } catch (Throwable) {
                Log::warning('Webhook enqueue failed; persisted delivery awaits recovery.', ['delivery_id' => $job->deliveryId]);
            }
        });
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('paykr.webhooks.max_retries'));
    }
}
