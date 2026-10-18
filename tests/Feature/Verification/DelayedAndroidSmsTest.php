<?php

declare(strict_types=1);

use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\AttributionEngine;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('retains delayed signed Android SMS for review without paying an ambiguous order', function (bool $newPayment, bool $oldTimestamp, string $expectedReason, int $delayMinutes = 0) {
    Bus::fake();
    Http::preventStrayRequests();
    config(['paykr.payments.grace_period_ms' => 0]);
    $this->travelTo(now()->startOfSecond());
    $merchant = Merchant::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $qr = app(AndroidObserver::class)->issue($merchant->id, $admin->id)->data;
    $pair = $this->postJson('/api/v1/pollers/android/pair', ['enrollmentToken' => $qr['token'], 'deviceName' => 'Isolated delay test'])->assertOk()->json();
    app(TenantContext::class)->run($merchant->id, function () use ($newPayment, $oldTimestamp, $expectedReason, $pair, $delayMinutes): void {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $old = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 100]);
        $queue = app(SlotQueueService::class);
        $queue->enqueueOrActivate($old->id, $account->id, new Actor(ActorType::SYSTEM));
        $originalSmsTime = now()->toImmutable()->addSecond();
        $this->travelTo($old->refresh()->expires_at->addSecond());
        app(PaymentTimingService::class)->expireIfDue($old->id);
        expect($old->refresh()->status->value)->toBe('EXPIRED');
        $this->travel($delayMinutes)->minutes();
        $next = null;
        if ($newPayment) {
            $next = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 100]);
            $queue->enqueueOrActivate($next->id, $account->id, new Actor(ActorType::SYSTEM));
            $this->travel(2)->seconds();
        }
        $body = json_encode(['sender' => 'VM-HDFCBK', 'rawText' => 'INR 1 credited UTR 123456789012', 'observedAt' => ($oldTimestamp ? $originalSmsTime : now())->getTimestampMs(), 'messageHash' => 'isolated-delay-case'], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestampMs();
        $response = $this->call('POST', '/api/v1/pollers/android/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $pair['deviceId'],
            'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.':'.$timestamp, $pair['signingKey']),
        ], $body)->assertOk();
        expect($old->refresh()->status->value)->toBe('EXPIRED');
        $response->assertJsonPath('status', 'NEEDS_REVIEW')->assertJsonPath('reason', $expectedReason);
        $observation = PaymentObservation::sole();
        expect($observation->attribution_status->value)->toBe('LATE_UNMATCHED');
        expect($observation->attributed_payment_id)->toBeNull();
        expect($observation->raw_payload['reviewReason'])->toBe($expectedReason);
        if ($next !== null) {
            expect($next->refresh()->status->value)->toBe('ACTIVE');
            expect($next->verification_level)->toBe(0);
        }
        // A fresh request signature must not create a second retained credit.
        $this->travel(1)->seconds();
        $timestamp = (string) now()->getTimestampMs();
        $this->call('POST', '/api/v1/pollers/android/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $pair['deviceId'],
            'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.':'.$timestamp, $pair['signingKey']),
        ], $body)->assertOk();
        expect(PaymentObservation::count())->toBe(1);
        app(AttributionEngine::class)->reattribute($observation->id);
        expect($observation->refresh()->attributed_payment_id)->toBeNull();
        // A different observer cannot promote evidence already held for review.
        if ($next !== null && ! $oldTimestamp) {
            $crossChannel = app(VerificationPipeline::class)->process(
                new VerificationData('GMAIL', 'INR 1 credited UTR 123456789012', $account->id,
                    sender: 'alerts@bank.in', receivedAt: now()->toImmutable()));
            expect($crossChannel->data['status'])->toBe('NEEDS_REVIEW');
            expect($next->refresh()->status->value)->toBe('ACTIVE');
            expect(PaymentObservation::whereNotNull('attributed_payment_id')->count())->toBe(0);
        }
        expect(SupportTicket::count())->toBe(0);
    });
    $this->travelBack();
})->with([
    'bank sends after expiry, no newer payment: retained for review' => [false, false, 'NO_ACTIVE_SESSION'],
    'offline phone submits original timestamp after expiry: retained for review' => [false, true, 'NO_ACTIVE_SESSION'],
    'offline old timestamp cannot match newer payment: retained for review' => [true, true, 'BEFORE_SESSION_CREATION'],
    'bank delay beyond the reconciliation lookback remains ambiguous' => [true, false, 'AMBIGUOUS_LATE_CREDIT', 60],
    'bank generates SMS during newer same-amount payment: held for review' => [true, false, 'AMBIGUOUS_LATE_CREDIT'],
]);
