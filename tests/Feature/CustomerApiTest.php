<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\CustomerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['paykr.sidecars.whatsapp_enabled' => true, 'paykr.internal_token' => 'test-internal']);
    Http::preventStrayRequests();
    Http::fake(fn () => config('testing.otp_failure') ? Http::response([], 503) : Http::response(['delivered' => true]));
});

it('registers, masks identity, authenticates, switches and revokes hashed customer sessions', function () {
    $registration = $this->postJson('/api/v1/customer/register', ['fullName' => 'Test Person', 'phoneNumber' => '9876543210', 'email' => 'person@example.com', 'password' => 'secret123'])->assertCreated()->assertCookie('paykr_session');
    $token = $registration->json('token');
    expect(CustomerSession::first()->token_hash)->toBe(hash('sha256', $token));
    $this->postJson('/api/v1/customer/check-user', ['mobileNumber' => '9876543210'])->assertOk()->assertExactJson(['exists' => true, 'mobileNumber' => '9876543210', 'maskedName' => 'T***** P*****']);
    $this->getJson('/api/v1/customer/me')->assertUnauthorized();
    $this->withToken($token)->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('customer.email', 'person@example.com')->assertJsonMissingPath('customer.password_hash');
    $this->postJson('/api/v1/customer/login', ['identifier' => 'person@example.com', 'password' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/v1/customer/login', ['identifier' => 'person@example.com', 'password' => 'secret123'])->assertOk();
    $this->postJson('/api/v1/customer/switch-account', ['sessionToken' => $token])->assertOk()->assertCookie('paykr_session');
    $this->withToken($token)->postJson('/api/v1/customer/logout')->assertOk();
    $this->withToken($token)->getJson('/api/v1/customer/me')->assertUnauthorized();
});

it('verifies a WhatsApp OTP once using the pending identity and preserves failed attempts', function () {
    $response = $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210', 'name' => 'Original Name', 'email' => 'original@example.com', 'isNewUser' => true])->assertOk()->assertJsonPath('dispatch.delivered', true);
    $otp = $response->json('devOtp');
    $wrong = $otp === '123456' ? '654321' : '123456';
    $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $wrong])->assertStatus(400)->assertJsonPath('code', 'OTP_INVALID');
    expect(CustomerOtpChallenge::first()->attempts)->toBe(1);
    $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $otp, 'name' => 'Attacker', 'email' => 'other@example.com'])->assertOk()->assertJsonPath('customer.email', 'original@example.com')->assertJsonPath('customer.isPhoneVerified', true);
    $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $otp])->assertStatus(400);
    Http::assertSentCount(1);
});

it('locks OTP verification after five failures and throttles resends', function () {
    $otp = $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertOk()->json('devOtp');
    $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertStatus(429);
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $otp === '123456' ? '654321' : '123456'])->assertStatus($i === 5 ? 429 : 400);
    }
    $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $otp])->assertStatus(429);
    expect(CustomerOtpChallenge::first()->attempts)->toBe(5);
});

it('handles dispatch failure, expiry and globally banned accounts without exposing credentials', function () {
    config(['testing.otp_failure' => true]);
    $otp = $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertOk()->assertJsonPath('dispatch.queued', true)->json('devOtp');
    $this->travel(301)->seconds();
    $this->postJson('/api/v1/customer/verify-otp', ['mobileNumber' => '9876543210', 'otp' => $otp])->assertStatus(400)->assertJsonPath('code', 'OTP_EXPIRED');
    $customer = Customer::factory()->create(['status' => 'BANNED']);
    $token = 'csess_'.str_repeat('a', 64);
    CustomerSession::factory()->create(['customer_id' => $customer->id, 'token_hash' => hash('sha256', $token)]);
    $this->withToken($token)->getJson('/api/v1/customer/me')->assertForbidden()->assertJsonPath('isBanned', true)->assertJsonPath('error', 'ACCESS BLOCKED');
    $this->getJson('/api/v1/customer/payments')->assertNotFound();
    $this->getJson('/api/v1/customer/tickets')->assertNotFound();
});

it('keeps development OTPs out of production responses and sets secure session cookies', function () {
    $this->app->instance('env', 'production');
    $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertOk()->assertJsonMissingPath('devOtp');
    $response = $this->postJson('/api/v1/customer/register', ['name' => 'Production Customer', 'mobileNumber' => '9876543211', 'password' => 'secret123'])->assertCreated();
    $cookie = $response->getCookie('paykr_session', false);
    expect($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');
    $this->app->instance('env', 'testing');
});

it('limits sends over the full mobile window and validates signup identity uniqueness', function () {
    $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210', 'isNewUser' => true])->assertStatus(422);
    Customer::factory()->create(['email' => 'taken@example.com']);
    $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210', 'isNewUser' => true, 'name' => 'Person', 'email' => 'taken@example.com'])->assertStatus(409);
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertOk();
        $this->travel(31)->seconds();
        // Redis TTL uses wall-clock time, so expire only the cooldown in this time-travel test.
        RateLimiter::clear('otp-cooldown:9876543210');
    }
    $this->postJson('/api/v1/customer/send-otp', ['mobileNumber' => '9876543210'])->assertStatus(429);
});
