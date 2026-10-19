<?php

declare(strict_types=1);

use App\Domain\Customers\Services\BanService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\Customer;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects a payment changed between its initial read and locked read', function (string $operation, bool $deleted) {
    $payment = app(TenantContext::class)->runUnscoped(fn () => PaymentSession::factory()->create());
    $dispatcher = PaymentSession::getEventDispatcher();
    PaymentSession::setEventDispatcher(clone $dispatcher);
    $reads = 0;
    PaymentSession::retrieved(function ($record) use ($payment, $deleted, &$reads) {
        if ($record->id === $payment->id && ++$reads === 1) {
            $query = DB::table('payment_sessions')->where('id', $record->id);
            if ($deleted) {
                $query->delete();
            } else {
                $record->receiving_account_id = 'stale-snapshot';
            }
        }
    });
    try {
        app(TenantContext::class)->run($payment->merchant_id, function () use ($payment, $operation, $deleted) {
            $run = fn () => $operation === 'timing'
                ? app(PaymentTimingService::class)->expireIfDue($payment->id)
                : app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
            expect($run)->toThrow(ValidationException::class, $deleted ? 'PAYMENT_NOT_FOUND' : 'INVALID_STATE_TRANSITION');
        });
    } finally {
        PaymentSession::setEventDispatcher($dispatcher);
    }
    expect(PaymentSession::withoutGlobalScopes()->find($payment->id)->status)->toBe(PaymentStatus::INITIATED);
})->with([['timing', false], ['transition', false], ['transition', true]]);

it('ignores a payment closed during a ban but propagates other transition failures', function (bool $closed) {
    $customer = Customer::factory()->create();
    $payment = app(TenantContext::class)->runUnscoped(fn () => PaymentSession::factory()->create(['customer_id' => $customer->id]));
    $dispatcher = PaymentSession::getEventDispatcher();
    PaymentSession::setEventDispatcher(clone $dispatcher);
    $reads = 0;
    PaymentSession::retrieved(function ($record) use ($payment, $closed, &$reads) {
        if ($record->id === $payment->id && ++$reads === 1) {
            if ($closed) {
                DB::table('payment_sessions')->where('id', $record->id)->update(['status' => 'CANCELLED']);
            } else {
                DB::table('payment_sessions')->where('id', $record->id)->delete();
            }
        }
    });
    try {
        $run = fn () => app(BanService::class)->expireLiveSessions($customer->id, new Actor(ActorType::SYSTEM));
        if ($closed) {
            $run();
            expect(PaymentSession::withoutGlobalScopes()->find($payment->id)->status)->toBe(PaymentStatus::CANCELLED);
        } else {
            expect($run)->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND');
        }
    } finally {
        PaymentSession::setEventDispatcher($dispatcher);
    }
})->with([true, false]);
