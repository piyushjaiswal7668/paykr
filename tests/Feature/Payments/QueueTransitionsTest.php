<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentCancelled;
use App\Domain\Payments\Events\PaymentExpired;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentSucceeded;
use App\Domain\Payments\Events\SessionActivated;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Real commits are essential: RefreshDatabase's outer test transaction hides commit timing.
beforeEach(fn () => $this->artisan('migrate:fresh'));
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function withinQueueMerchant(Closure $test): void
{
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($test, $merchant) {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $test($account, app(SlotQueueService::class), app(PaymentTransitionService::class), new Actor(ActorType::SYSTEM), $merchant);
    });
}

it('permits valid transitions and denies illegal transitions', function () {
    expect(PaymentStatus::INITIATED->canTransitionTo(PaymentStatus::ACTIVE))->toBeTrue();
    expect(PaymentStatus::ACTIVE->canTransitionTo(PaymentStatus::PROCESSING))->toBeTrue();
    expect(PaymentStatus::PROCESSING->canTransitionTo(PaymentStatus::SUCCESS))->toBeTrue();
    expect(PaymentStatus::PROCESSING->canTransitionTo(PaymentStatus::FAILED))->toBeTrue();
    expect(PaymentStatus::ACTIVE->canTransitionTo(PaymentStatus::EXPIRED))->toBeTrue();
    expect(PaymentStatus::ACTIVE->canTransitionTo(PaymentStatus::CANCELLED))->toBeTrue();
    expect(PaymentStatus::INITIATED->canTransitionTo(PaymentStatus::SUCCESS))->toBeFalse();
    expect(PaymentStatus::QUEUED->canTransitionTo(PaymentStatus::PROCESSING))->toBeFalse();
    foreach ([PaymentStatus::SUCCESS, PaymentStatus::FAILED, PaymentStatus::EXPIRED, PaymentStatus::CANCELLED] as $closed) {
        foreach (PaymentStatus::cases() as $target) {
            expect($closed->canTransitionTo($target))->toBeFalse();
        }
    }
});

it('maps internal terminal states to public contract outcomes', function () {
    foreach (PaymentStatus::cases() as $status) {
        if ($status->isTerminal()) {
            expect($status->publicOutcome())->toBe($status->value);
        } else {
            expect(fn () => $status->publicOutcome())->toThrow(ValidationException::class);
        }
    }
});

it('Queue Engine correctly assigns active slot and queued positions with wait estimates', function () {
    $this->freezeTime();
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $payments = PaymentSession::factory()->forAccount($account)->count(3)->create();
        foreach ($payments as $payment) {
            $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        }
        expect($payments[0]->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payments[1]->refresh()->queue_position)->toBe(1);
        expect($payments[2]->refresh()->queue_position)->toBe(2);
        expect($queue->estimatedWaitSeconds($account->id, 0))->toBe(0);
        expect($queue->estimatedWaitSeconds($account->id, 1))->toBe(300);
        expect($queue->estimatedWaitSeconds($account->id, 2))->toBe(630);
        $expires = $payments[0]->expires_at;
        $queue->enqueueOrActivate($payments[0]->id, $account->id, $actor);
        $queue->enqueueOrActivate($payments[1]->id, $account->id, $actor);
        expect($payments[0]->refresh()->expires_at->equalTo($expires))->toBeTrue();
        expect(AuditLog::where('action', 'PAYMENT_SLOT_ACTIVATED')->count())->toBe(1);
        expect(AuditLog::where('action', 'PAYMENT_ENQUEUED')->count())->toBe(2);
        $transitions->transition($payments[0]->id, PaymentStatus::PROCESSING, $actor);
        expect($queue->enqueueOrActivate($payments[0]->id, $account->id, $actor)->status)->toBe(PaymentStatus::PROCESSING);
        expect($queue->estimatedWaitSeconds($account->id, 1))->toBe(30);
        expect($queue->promoteNext($account->id))->toBeNull();
        $transitions->transition($payments[0]->id, PaymentStatus::SUCCESS, $actor);
        expect($payments[1]->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payments[2]->refresh()->queue_position)->toBe(1);
        expect($account->refresh()->current_active_session_id)->toBe($payments[1]->id);
        expect(AuditLog::where('action', 'QUEUE_PROMOTION_TO_ACTIVE')->count())->toBe(1);
    });
});

it('emits each terminal event once after commit and never resurrects a closed session', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        foreach ([PaymentStatus::SUCCESS, PaymentStatus::FAILED, PaymentStatus::EXPIRED, PaymentStatus::CANCELLED] as $target) {
            $class = match ($target) {
                PaymentStatus::SUCCESS => PaymentSucceeded::class,
                PaymentStatus::FAILED => PaymentFailed::class,
                PaymentStatus::EXPIRED => PaymentExpired::class,
                default => PaymentCancelled::class,
            };
            $events = [];
            Event::listen($class, function ($event) use (&$events) {
                $events[] = $event;
            });
            $payment = PaymentSession::factory()->forAccount($account)->create();
            $queue->enqueueOrActivate($payment->id, $account->id, $actor);
            $transitions->transition($payment->id, PaymentStatus::PROCESSING, $actor);
            DB::transaction(function () use ($transitions, $payment, $target, $actor, &$events) {
                $transitions->transition($payment->id, $target, $actor);
                expect($events)->toHaveCount(0);
            });
            expect($events)->toHaveCount(1);
            expect($events[0]->paymentId)->toBe($payment->id);
            expect($payment->refresh()->completed_at)->not->toBeNull();
            expect($account->refresh()->is_slot_busy)->toBeFalse();
            foreach (PaymentStatus::cases() as $next) {
                expect(fn () => $transitions->transition($payment->id, $next, $actor))->toThrow(ValidationException::class);
            }
            expect($events)->toHaveCount(1);
        }
    });
});

it('rolls back state slot audit and events when the enclosing transaction fails', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        Event::fake([SessionActivated::class]);
        $payment = PaymentSession::factory()->forAccount($account)->create();
        expect(fn () => DB::transaction(function () use ($queue, $payment, $account, $actor) {
            $queue->enqueueOrActivate($payment->id, $account->id, $actor);
            throw new RuntimeException('rollback');
        }))->toThrow(RuntimeException::class);
        expect($payment->refresh()->status)->toBe(PaymentStatus::INITIATED);
        expect($account->refresh()->is_slot_busy)->toBeFalse();
        expect(AuditLog::count())->toBe(0);
        Event::assertNotDispatched(SessionActivated::class);
    });
});

it('expires stale active owners and preserves FIFO before new arrivals', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $payments = PaymentSession::factory()->forAccount($account)->count(3)->create();
        $queue->enqueueOrActivate($payments[0]->id, $account->id, $actor);
        $queue->enqueueOrActivate($payments[1]->id, $account->id, $actor);
        $payments[0]->update(['expires_at' => now()->subSecond()]);
        $queue->enqueueOrActivate($payments[2]->id, $account->id, $actor);
        expect($payments[0]->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect($payments[1]->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payments[2]->refresh()->queue_position)->toBe(1);
        $transitions->transition($payments[1]->id, PaymentStatus::PROCESSING, $actor);
        $payments[1]->update(['processing_expires_at' => now()->subSecond()]);
        $queue->promoteNext($account->id);
        expect($payments[1]->refresh()->status)->toBe(PaymentStatus::FAILED);
        expect($payments[2]->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('keeps counter payments independent and rejects declines after closure', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $upi = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($upi->id, $account->id, $actor);
        foreach ([PaymentMethod::CASH_COUNTER, PaymentMethod::CARD_COUNTER, PaymentMethod::COD] as $method) {
            $payment = PaymentSession::factory()->forAccount($account)->create(['method' => $method]);
            expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class);
            $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
            $target = $method === PaymentMethod::COD ? PaymentStatus::SUCCESS : PaymentStatus::FAILED;
            $transitions->transition($payment->id, $target, $actor);
            expect(fn () => $transitions->transition($payment->id, PaymentStatus::FAILED, $actor))->toThrow(ValidationException::class);
            expect($account->refresh()->current_active_session_id)->toBe($upi->id);
        }
    });
});

it('rejects invalid transitions unavailable accounts and cross-tenant requests', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor, $merchant) {
        $payment = PaymentSession::factory()->forAccount($account)->create();
        expect(fn () => $transitions->transition($payment->id, PaymentStatus::SUCCESS, $actor))->toThrow(ValidationException::class);
        expect(fn () => $transitions->transition('missing', PaymentStatus::ACTIVE, $actor))->toThrow(ValidationException::class);
        expect(fn () => $queue->enqueueOrActivate('missing', $account->id, $actor))->toThrow(ValidationException::class);
        expect(fn () => $queue->enqueueOrActivate($payment->id, 'missing', $actor))->toThrow(ValidationException::class);
        $otherActor = new Actor(ActorType::MERCHANT_USER, merchantId: 'other');
        expect(fn () => $transitions->transition($payment->id, PaymentStatus::ACTIVE, $otherActor))->toThrow(ValidationException::class);
        expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $otherActor))->toThrow(ValidationException::class);
        app(TenantContext::class)->run(Merchant::factory()->create()->id, function () use ($payment, $account, $queue, $transitions, $actor) {
            expect(fn () => $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor))->toThrow(ValidationException::class);
            expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class);
        });
        $account->refresh()->update(['is_active' => false]);
        expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class);
        expect(fn () => $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor))->toThrow(ValidationException::class);
        expect($queue->promoteNext($account->id))->toBeNull();
        $account->refresh()->update(['is_active' => true]);
        $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        $next = PaymentSession::factory()->forAccount($account)->create();
        expect(fn () => $transitions->transition($next->id, PaymentStatus::ACTIVE, $actor))->toThrow(ValidationException::class);
        $payment->update(['expires_at' => now()->subSecond()]);
        expect(fn () => $transitions->transition($payment->id, PaymentStatus::PROCESSING, $actor))->toThrow(ValidationException::class);
        expect($queue->enqueueOrActivate($payment->id, $account->id, $actor)->status)->toBe(PaymentStatus::EXPIRED);
        expect(fn () => $queue->enqueueOrActivate($payment->id, $account->id, $actor))->toThrow(ValidationException::class);
        expect($queue->estimatedWaitSeconds($account->id, 1))->toBe(0);
    });
});

it('repairs stale slot flags and reindexes a cancelled waiting session without releasing the owner', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $payments = PaymentSession::factory()->forAccount($account)->count(3)->create();
        foreach ($payments as $payment) {
            $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        }
        $account->refresh()->update(['is_slot_busy' => false, 'current_active_session_id' => null]);
        $queue->promoteNext($account->id);
        expect($account->refresh()->current_active_session_id)->toBe($payments[0]->id);
        $transitions->transition($payments[1]->id, PaymentStatus::CANCELLED, $actor);
        expect($payments[2]->refresh()->queue_position)->toBe(1);
        expect($account->refresh()->current_active_session_id)->toBe($payments[0]->id);
    });
});

it('rejects missing accounts and inconsistent owners without stealing a slot', function () {
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $unbound = PaymentSession::factory()->create();
        expect(fn () => $transitions->transition($unbound->id, PaymentStatus::ACTIVE, $actor))->toThrow(ValidationException::class);
        $transitions->transition($unbound->id, PaymentStatus::CANCELLED, $actor);
        $payment = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        $account->refresh()->update(['current_active_session_id' => null]);
        expect(fn () => $transitions->transition($payment->id, PaymentStatus::PROCESSING, $actor))->toThrow(ValidationException::class);
        $queue->promoteNext($account->id);
        $broken = PaymentSession::factory()->forAccount($account)->active()->create();
        expect(fn () => $queue->promoteNext($account->id))->toThrow(ValidationException::class);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($broken->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($account->refresh()->current_active_session_id)->toBe($payment->id);
    });
});

it('expires an owner exactly at its deadline and permits trusted direct verification of an active payment', function () {
    $this->freezeTime();
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $payment = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($payment->id, $account->id, $actor);
        $transitions->transition($payment->id, PaymentStatus::SUCCESS, $actor);
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        $next = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($next->id, $account->id, $actor);
        $next->update(['expires_at' => now()]);
        $queue->promoteNext($account->id);
        expect($next->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect($account->refresh()->is_slot_busy)->toBeFalse();
    });
});

it('expires the five minute active window and promotes the next customer at the exact deadline', function () {
    $this->freezeTime();
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $first = PaymentSession::factory()->forAccount($account)->create();
        $second = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($first->id, $account->id, $actor);
        $queue->enqueueOrActivate($second->id, $account->id, $actor);
        $timing = app(PaymentTimingService::class);
        $this->travel(299)->seconds();
        expect($timing->expireIfDue($first->id)->status)->toBe(PaymentStatus::ACTIVE);
        $this->travel(1)->seconds();
        expect($timing->expireIfDue($first->id)->status)->toBe(PaymentStatus::EXPIRED);
        expect($second->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($timing->expireIfDue($first->id)->status)->toBe(PaymentStatus::EXPIRED);
    });
});

it('keeps Payment Done idempotent and recovers overdue processing without reviving closed orders', function () {
    $this->freezeTime();
    withinQueueMerchant(function ($account, $queue, $transitions, $actor) {
        $first = PaymentSession::factory()->forAccount($account)->create();
        $second = PaymentSession::factory()->forAccount($account)->create();
        $queue->enqueueOrActivate($first->id, $account->id, $actor);
        $queue->enqueueOrActivate($second->id, $account->id, $actor);
        $timing = app(PaymentTimingService::class);
        $processing = $timing->paymentDone($first->id, $actor);
        $deadline = $processing->processing_expires_at;
        $this->travel(20)->seconds();
        expect($timing->paymentDone($first->id, $actor)->processing_expires_at->equalTo($deadline))->toBeTrue();
        expect($timing->expireIfDue($first->id)->status)->toBe(PaymentStatus::PROCESSING);
        $this->travel(10)->seconds();
        $timing->recover();
        expect($first->refresh()->status)->toBe(PaymentStatus::FAILED);
        expect($second->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($timing->paymentDone($first->id, $actor)->status)->toBe(PaymentStatus::FAILED);
        expect(fn () => $timing->paymentDone($second->id, new Actor(ActorType::SYSTEM, merchantId: 'wrong')))->toThrow(ValidationException::class, 'FORBIDDEN');
        expect(fn () => $timing->expireIfDue('missing'))->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND');
        expect(AuditLog::where('action', 'PAYMENT_PROCESSING')->count())->toBe(1);
        expect(AuditLog::where('action', 'PAYMENT_FAILED')->count())->toBe(1);
    });
});
