<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Jobs\ExpireActivePayment;
use App\Domain\Payments\Jobs\FailProcessingPayment;
use App\Domain\Payments\Services\PaymentMethodService;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Platform\Services\ClockStatus;
use App\Domain\Platform\Services\PaymentClock;
use App\Domain\Platform\Services\SystemOverview;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Domain\Verification\Jobs\ReattributePendingObservations;
use App\Domain\Verification\Services\AttributionEngine;
use App\Filament\Admin\Pages\System;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    $this->travelTo(now()->startOfSecond());
    config(['cache.default' => 'array']);
    Bus::fake();
    Cache::flush();
});
afterEach(fn () => RefreshDatabaseState::$migrated = false);

function clockMerchant(Closure $test): void
{
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant, $test) {
        $account = PaymentReceivingAccount::factory()->active()->create();
        $payment = PaymentSession::factory()->forAccount($account)->create();
        app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
        $test($merchant, $account, $payment->refresh());
    });
}

it('dispatches committed deadlines on payments queue and safely ignores early stale duplicate or cross-tenant jobs', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        Bus::assertDispatched(ExpireActivePayment::class, fn ($job) => $job->queue === 'payments' && $job->delay->greaterThanOrEqualTo($payment->expires_at));
        $activeJob = new ExpireActivePayment($merchant->id, $payment->id);
        app()->call([$activeJob, 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        app(PaymentTimingService::class)->paymentDone($payment->id, new Actor(ActorType::SYSTEM));
        Bus::assertDispatched(FailProcessingPayment::class);
        $this->travel(30)->seconds();
        app()->call([$activeJob, 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::PROCESSING);
        app()->call([new FailProcessingPayment('wrong', $payment->id), 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::PROCESSING);
        app()->call([new FailProcessingPayment($merchant->id, $payment->id), 'handle']);
        app()->call([new FailProcessingPayment($merchant->id, $payment->id), 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::FAILED);
    });
});

it('recovers overdue active and processing sessions and requeues live deadlines', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        $payment->update(['expires_at' => now()->subSecond()]);
        $queued = PaymentSession::factory()->forAccount($account)->create(['status' => 'QUEUED', 'queue_position' => 1]);
        $cod = PaymentSession::factory()->create(['method' => 'COD', 'status' => 'PROCESSING', 'processing_expires_at' => now()->subSecond()]);
        $counts = app(PaymentClock::class)->tick(true);
        expect($counts['expiredCount'])->toBe(1)->and($counts['failedCount'])->toBe(1)->and($counts['promotedCount'])->toBe(1);
        expect($payment->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect($queued->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($cod->refresh()->status)->toBe(PaymentStatus::FAILED);
        expect(app(PaymentClock::class)->tick(true)['rescheduledCount'])->toBe(1);
        Bus::assertDispatched(ReattributePendingObservations::class);
    });
});

it('decouples COD timer from the UPI slot and ignores the original UPI expiry after switching', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        MerchantPaymentSettings::factory()->create(['cod_enabled' => true]);
        $payment->update(['expires_at' => now()->addSeconds(30)]);
        $queued = PaymentSession::factory()->forAccount($account)->create();
        app(SlotQueueService::class)->enqueueOrActivate($queued->id, $account->id, new Actor(ActorType::SYSTEM));
        $switched = app(PaymentMethodService::class)->select($payment->id, PaymentMethod::COD, new Actor(ActorType::SYSTEM));
        expect($switched->receiving_account_id)->toBeNull()->and($switched->queue_position)->toBe(0);
        expect($switched->expires_at->equalTo(now()->addSeconds(300)))->toBeTrue();
        expect($queued->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        $this->travel(30)->seconds();
        app()->call([new ExpireActivePayment($merchant->id, $payment->id), 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        $queued->update(['expires_at' => now()->addMinutes(10)]);
        $this->travel(270)->seconds();
        app()->call([new ExpireActivePayment($merchant->id, $payment->id), 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect($account->refresh()->current_active_session_id)->toBe($queued->id);
    });
});

it('reattributes only pipeline-approved persisted observations and preserves the existing observation ID', function () {
    config(['paykr.payments.grace_period_ms' => 0]);
    clockMerchant(function ($merchant, $account, $payment) {
        $observation = PaymentObservation::factory()->create(['receiving_account_id' => $account->id, 'amount_paise' => $payment->amount_paise, 'observed_at' => now(), 'attribution_status' => 'UNATTRIBUTED', 'attributed_payment_id' => null]);
        $job = new ReattributePendingObservations($merchant->id, $account->id);
        app()->call([$job, 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        DB::table('payment_observations')->where('id', $observation->id)->update(['verified_at' => now()]);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS)->and($payment->attributed_observation_id)->toBe($observation->id);
        expect(PaymentObservation::count())->toBe(1);
        app(AttributionEngine::class)->reattribute('missing');
    });
});

it('exposes safe clock heartbeat and startup counts without treating stale workers as healthy', function () {
    expect(app(ClockStatus::class)->read()['healthy'])->toBeFalse();
    Cache::put('paykr:clock:status', ['status' => 'RUNNING', 'heartbeat' => now()->getTimestamp(), 'recovery' => ['expiredCount' => 1]]);
    $this->getJson('/health')->assertJsonPath('clock.healthy', true)->assertJsonPath('clock.recovery.expiredCount', 1);
    expect(app(System::class)->clockStatus()['healthy'])->toBeTrue();
    $this->travel(4)->seconds();
    expect(app(ClockStatus::class)->read()['healthy'])->toBeFalse();
});

it('keeps counter payments untimed and requeues switching back to UPI without stealing a slot', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        $settings = MerchantPaymentSettings::factory()->create(['selling_mode' => 'OFFLINE', 'cash_enabled' => true]);
        $service = app(PaymentMethodService::class);
        $actor = new Actor(ActorType::SYSTEM);
        expect(fn () => $service->select($payment->id, PaymentMethod::COD, $actor))->toThrow(ValidationException::class, 'PAYMENT_METHOD_DISABLED');
        expect(fn () => $service->select($payment->id, PaymentMethod::CARD_COUNTER, $actor))->toThrow(ValidationException::class, 'PAYMENT_METHOD_DISABLED');
        $queued = PaymentSession::factory()->forAccount($account)->create();
        app(SlotQueueService::class)->enqueueOrActivate($queued->id, $account->id, $actor);
        $counter = $service->select($payment->id, PaymentMethod::CASH_COUNTER, $actor);
        expect($counter->expires_at)->toBeNull();
        expect($service->select($payment->id, PaymentMethod::CASH_COUNTER, $actor)->expires_at)->toBeNull();
        $this->travel(600)->seconds();
        expect(app(PaymentTimingService::class)->expireIfDue($payment->id)->status)->toBe(PaymentStatus::ACTIVE);
        $queued->update(['expires_at' => now()->addMinutes(5)]);
        expect($service->select($payment->id, PaymentMethod::UPI, $actor)->status)->toBe(PaymentStatus::QUEUED);
        expect($account->refresh()->current_active_session_id)->toBe($queued->id);
        expect(fn () => $service->select($payment->id, PaymentMethod::CASH_COUNTER, new Actor(ActorType::SYSTEM, merchantId: 'wrong')))->toThrow(ValidationException::class, 'FORBIDDEN');
        $payment->update(['verification_level' => 1]);
        expect(fn () => $service->select($payment->id, PaymentMethod::CASH_COUNTER, $actor))->toThrow(ValidationException::class, 'INVALID_STATE_TRANSITION');
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        expect(fn () => $service->select($payment->id, PaymentMethod::CASH_COUNTER, $actor))->toThrow(ValidationException::class, 'PAYMENT_CLOSED');
        expect(fn () => $service->select('missing', PaymentMethod::UPI, $actor))->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND');
    });
});

it('resolves missed grace and expiry jobs in persisted deadline order', function (int $grace, string $expected) {
    clockMerchant(function ($merchant, $account, $payment) use ($grace, $expected) {
        $observation = PaymentObservation::factory()->create(['receiving_account_id' => $account->id, 'amount_paise' => $payment->amount_paise, 'attributed_payment_id' => $payment->id, 'attribution_status' => 'ATTRIBUTED']);
        $payment->update(['expires_at' => now()->addSeconds(3), 'verification_grace_expires_at' => now()->addSeconds($grace), 'attributed_observation_id' => $observation->id, 'verification_level' => 1]);
        $this->travel(10)->seconds();
        app()->call([new ExpireActivePayment($merchant->id, $payment->id), 'handle']);
        app(AttributionEngine::class)->finalizeLevel1($payment->id);
        expect($payment->refresh()->status->value)->toBe($expected);
    });
})->with([[2, 'SUCCESS'], [4, 'EXPIRED']]);

it('requeues future grace finalization on startup without confirming it early', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        $payment->update(['verification_grace_expires_at' => now()->addSeconds(5)]);
        $counts = app(PaymentClock::class)->tick(true);
        expect($counts['rescheduledCount'])->toBe(2);
        Bus::assertDispatched(FinalizeLevel1Payment::class, fn ($job) => $job->paymentId === $payment->id && $job->delay->equalTo(now()->addSeconds(5)));
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('keeps clock recovery running when dispatch fails and releases observer throttles', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        $payment->update(['verification_grace_expires_at' => now()->addMinute()]);
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh']);
        $idle = Merchant::factory()->create();
        app(TenantContext::class)->run($idle->id, fn () => MerchantGmailConnection::factory()->create(['refresh_token' => 'idle-refresh']));
        Bus::swap(Mockery::mock(Dispatcher::class));
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
        $counts = app(PaymentClock::class)->tick(true);
        expect($counts['expiredCount'])->toBe(0);
        expect($payment->fresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payment->fresh()->verification_grace_expires_at)->not->toBeNull();
        expect(Cache::has('paykr:observers:dispatch:'.$merchant->id.':active'))->toBeFalse();
        expect(Cache::has('paykr:observers:dispatch:'.$idle->id.':idle'))->toBeFalse();
    });
});

it('clears elapsed grace deadlines even when the attributed observation has disappeared', function () {
    clockMerchant(function ($merchant, $account, $payment) {
        $payment->update(['verification_grace_expires_at' => now()->subSecond(), 'verification_level' => 1]);
        $renewals = 0;
        app(PaymentClock::class)->tick(false, function () use (&$renewals) {
            $renewals++;
        });
        expect($payment->fresh()->verification_grace_expires_at)->toBeNull();
        expect($renewals)->toBeGreaterThan(0);
    });
});

it('reports a listening realtime server and closes the health probe connection', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    expect($server)->not->toBeFalse();
    $address = stream_socket_get_name($server, false);
    config(['reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => (int) substr(strrchr($address, ':'), 1)]);
    try {
        expect(app(SystemOverview::class)->read()['Reverb'])->toBe('Listening');
    } finally {
        fclose($server);
    }
});
