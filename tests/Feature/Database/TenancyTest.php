<?php

declare(strict_types=1);

use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isolates merchant reads writes and deletes and restores nested contexts', function () {
    $a = Merchant::factory()->create();
    $b = Merchant::factory()->create();
    $context = app(TenantContext::class);
    $first = $context->run($a->id, fn () => PaymentSession::factory()->create());
    $second = $context->run($b->id, fn () => PaymentSession::factory()->create());
    expect(PaymentSession::count())->toBe(0);
    expect(fn () => PaymentSession::factory()->create())->toThrow(ValidationException::class);
    expect(fn () => $first->delete())->toThrow(ValidationException::class);
    $context->run($a->id, function () use ($first, $second, $a, $b, $context) {
        expect(PaymentSession::pluck('id')->all())->toBe([$first->id]);
        expect(PaymentSession::find($second->id))->toBeNull();
        expect(PaymentSession::whereKey($second->id)->update(['queue_position' => 7]))->toBe(0);
        expect(fn () => $second->update(['queue_position' => 1]))->toThrow(ValidationException::class);
        expect(fn () => $second->delete())->toThrow(ValidationException::class);
        expect(fn () => PaymentSession::factory()->create(['merchant_id' => $b->id]))->toThrow(ValidationException::class);
        expect(fn () => $first->update(['merchant_id' => $b->id]))->toThrow(ValidationException::class);
        expect(fn () => $context->run($b->id, fn () => throw new RuntimeException('test')))->toThrow(RuntimeException::class);
        expect($context->merchantId())->toBe($a->id);
    });
    expect($context->merchantId())->toBeNull()->and($context->isUnscoped())->toBeFalse();
    expect($context->runUnscoped(fn () => PaymentSession::count()))->toBe(2);
});

it('enforces one active VPA per merchant while allowing many inactive accounts', function () {
    $context = app(TenantContext::class);
    $merchant = Merchant::factory()->create();
    $context->run($merchant->id, function () {
        PaymentReceivingAccount::factory()->active()->create();
        PaymentReceivingAccount::factory()->count(2)->create();
        expect(PaymentReceivingAccount::count())->toBe(3);
        expect(fn () => PaymentReceivingAccount::factory()->active()->create())->toThrow(QueryException::class);
    });
    $context->run(Merchant::factory()->create()->id, fn () => PaymentReceivingAccount::factory()->active()->create());
});

it('rejects references to another merchants receiving account at the database layer', function () {
    $context = app(TenantContext::class);
    $merchant = Merchant::factory()->create();
    $account = $context->run($merchant->id, fn () => PaymentReceivingAccount::factory()->create());
    $other = Merchant::factory()->create();
    expect(fn () => $context->run($other->id, fn () => PaymentSession::factory()->create(['receiving_account_id' => $account->id])))->toThrow(QueryException::class);
});

it('enforces positive integer payment amounts in MySQL', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL CHECK constraint');
    }
    $merchant = Merchant::factory()->create();
    expect(fn () => app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->create(['amount_paise' => 0])))->toThrow(QueryException::class);
});
