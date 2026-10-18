<?php

declare(strict_types=1);

use App\Domain\Observers\Services\MessagesSidecar;
use App\Domain\Observers\Services\UpiHealthService;
use App\Domain\Observers\Services\WhatsAppOtp;
use App\Domain\Observers\Services\WhatsAppSidecar;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['paykr.internal_token' => str_repeat('t', 32), 'paykr.sidecars.whatsapp_enabled' => true, 'paykr.sidecars.messages_enabled' => true]);
});

it('opens the circuit on failure, suppresses repeated calls, and recovers after fifteen seconds', function () {
    Http::fakeSequence()->pushStatus(503)->push(['delivered' => true]);
    $otp = app(WhatsAppOtp::class);
    expect($otp->send('9876543210', '123456', 'Test', 'Paykr')['delivered'])->toBeFalse();
    expect($otp->send('9876543210', '123456', 'Test', 'Paykr')['queued'])->toBeTrue();
    Http::assertSentCount(1);
    $this->travel(16)->seconds();
    expect($otp->send('9876543210', '123456', 'Test', 'Paykr')['delivered'])->toBeTrue();
    Http::assertSentCount(2);
});

it('degrades when connection fails and never sends to disabled or non-loopback services', function () {
    Http::fake(fn () => throw new ConnectionException('down'));
    expect(app(WhatsAppSidecar::class)->status())->toBeNull();
    Cache::flush();
    config(['paykr.sidecars.whatsapp_enabled' => false]);
    expect(app(WhatsAppSidecar::class)->connect())->toBeNull();
    config(['paykr.sidecars.whatsapp_enabled' => true, 'paykr.sidecars.whatsapp_url' => 'https://example.com']);
    expect(app(WhatsAppSidecar::class)->connect())->toBeNull();
    Http::assertSentCount(0);
});

it('provides authenticated clients for pairing, disconnect, messages and OTP with short deadlines', function () {
    Http::fake(['*' => Http::response(['status' => 'CONNECTED', 'isPaired' => true, 'mode' => 'BACKGROUND', 'lastSeenAt' => now()->toIso8601String()])]);
    $wa = app(WhatsAppSidecar::class);
    expect($wa->status()['status'])->toBe('CONNECTED');
    $wa->connect();
    $wa->pairingCode('919876543210');
    $wa->disconnect();
    $wa->send('919876543210', 'Test');
    $messages = app(MessagesSidecar::class);
    $messages->pair('merchant');
    $messages->sweep('merchant');
    expect($messages->health('merchant')->merchantId)->toBe('merchant');
    Http::assertSentCount(8);
    Http::assertSent(fn ($request) => $request->hasHeader('X-Paykr-Internal-Token', str_repeat('t', 32)) && str_ends_with($request->url(), '/pairing-code') && $request['phoneNumber'] === '919876543210');
});

it('uses fresh merchant sidecar health and degrades to yellow or red when it goes down', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        Http::fakeSequence()->push(['status' => 'CONNECTED', 'isPaired' => true, 'mode' => 'BACKGROUND', 'lastSeenAt' => now()->toIso8601String()])->pushStatus(503);
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh', 'last_successful_fetch' => now()]);
        expect(app(UpiHealthService::class)->check()['tier'])->toBe('GREEN');
        expect(app(UpiHealthService::class)->check()['tier'])->toBe('YELLOW');
        MerchantGmailConnection::query()->delete();
        expect(app(UpiHealthService::class)->check()['tier'])->toBe('RED');
        Http::assertSentCount(2);
    });
});

it('rejects malformed and stale sidecar health', function () {
    Http::fakeSequence()->push(['lastSeenAt' => 'not-a-date'])->push('invalid json');
    expect(app(MessagesSidecar::class)->health('merchant'))->toBeNull();
    expect(app(MessagesSidecar::class)->health('merchant'))->toBeNull();
});

it('accepts only authenticated loopback status pushes and persists no QR or pairing secrets', function () {
    $this->postJson('/internal/whatsapp/status', ['status' => 'CONNECTED'])->assertForbidden();
    $this->withHeader('X-Paykr-Internal-Token', str_repeat('t', 32));
    $this->postJson('/internal/whatsapp/status', ['status' => 'INVALID'])->assertUnprocessable();
    $this->postJson('/internal/whatsapp/status', ['status' => 'CONNECTED', 'phoneNumber' => '919876543210', 'qrCode' => 'secret', 'pairingCode' => 'secret'])->assertOk()->assertExactJson(['success' => true]);
    expect(WhatsappSession::count())->toBe(1);
    expect(WhatsappSession::first()->phone_number)->toBe('919876543210');
    expect(WhatsappSession::first()->last_connected_at)->not->toBeNull();
    expect(json_encode(WhatsappSession::first()->getAttributes()))->not->toContain('secret');
    $this->postJson('/internal/whatsapp/status', ['status' => 'DISCONNECTED'])->assertOk();
    expect(WhatsappSession::count())->toBe(1);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson('/internal/whatsapp/status', ['status' => 'CONNECTED'])->assertForbidden();
});
