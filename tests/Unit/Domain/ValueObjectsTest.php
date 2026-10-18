<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\MobileNumber;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Vpa;

it('converts money without floating point arithmetic', function () {
    expect(Money::fromRupees('001.01')->paise)->toBe(101)
        ->and(Money::fromRupees(499)->toRupeesString())->toBe('499.00')
        ->and(Money::fromRupees(1.25)->paise)->toBe(125)
        ->and(Money::fromRupees(' 0.01 ')->formatINR())->toBe('₹0.01')
        ->and((new Money(0))->toRupeesString())->toBe('0.00')
        ->and(Money::fromRupees('92233720368547758.07')->paise)->toBe(PHP_INT_MAX);
});

it('rejects invalid or overflowing money', function ($amount) {
    expect(fn () => Money::fromRupees($amount))->toThrow(ValidationException::class, 'INVALID_AMOUNT');
})->with(['0', '-1', '1.001', '1e2', '', 'NaN', '1.', '.5', '92233720368547758.08', '999999999999999999999', INF, NAN]);

it('rejects negative paise', fn () => expect(fn () => new Money(-1))->toThrow(ValidationException::class));

it('normalizes mobile numbers and validates VPAs', function () {
    expect((new MobileNumber('+91 98765-43210'))->value)->toBe('9876543210')
        ->and((new Vpa('merchant.name@upi'))->value)->toBe('merchant.name@upi');
    expect(fn () => new MobileNumber('123'))->toThrow(ValidationException::class, 'INVALID_MOBILE');
    expect(fn () => new Vpa('a@b'))->toThrow(ValidationException::class, 'INVALID_VPA');
});

it('carries actor identity explicitly', function () {
    $actor = new Actor(ActorType::MERCHANT_USER, 'u', 'm', '127.0.0.1');
    expect($actor->id)->toBe('u')->and($actor->merchantId)->toBe('m');
});

it('has a safe translated message and HTTP status for every error', function () {
    foreach (ErrorCode::cases() as $code) {
        expect($code->httpStatus())->toBeGreaterThanOrEqual(400)->toBeLessThan(600);
        expect(__('errors.'.$code->value))->not->toBe('errors.'.$code->value);
    }
});

it('ports all legacy valid amount and VPA examples', function () {
    foreach (['200.00' => 20000, '200' => 20000, '0.50' => 50, '0.05' => 5, '199.99' => 19999] as $rupees => $paise) {
        expect(Money::fromRupees($rupees)->paise)->toBe($paise);
        expect((new Money($paise))->formatINR())->toBe('₹'.(new Money($paise))->toRupeesString());
    }
    foreach (['merchant@icici', 'user.name-12@okhdfcbank'] as $vpa) {
        expect((new Vpa($vpa))->value)->toBe($vpa);
    }
    foreach (['invalid-vpa-no-bank', '@bank', ''] as $vpa) {
        expect(fn () => new Vpa($vpa))->toThrow(ValidationException::class);
    }
});
