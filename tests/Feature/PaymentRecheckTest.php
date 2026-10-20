<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake();
    $this->merchant = Merchant::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->token = 'csess_'.str_repeat('r', 64);
    CustomerSession::factory()->create(['customer_id' => $this->customer->id, 'token_hash' => hash('sha256', $this->token)]);
    $this->device = Device::factory()->create(['device_token_hash' => hash('sha256', 'device-proof')]);
    $this->key = 'sk_live_'.str_repeat('r', 64);
    app(TenantContext::class)->run($this->merchant->id, function () {
        MerchantApiKey::factory()->create(['key_hash' => hash('sha256', $this->key), 'type' => 'secret', 'mode' => 'live']);
        $this->account = PaymentReceivingAccount::factory()->active()->create();
        $this->payment = PaymentSession::factory()->forAccount($this->account)->active()->create(['device_id' => $this->device->id, 'customer_id' => $this->customer->id]);
        $this->account->update(['current_active_session_id' => $this->payment->id, 'is_slot_busy' => true]);
    });
    $this->api = '/api/v1/payments/'.$this->payment->id;
    $this->checkout = '/api/v1/checkout/'.$this->payment->id;
});

it('returns awaiting evidence without restarting the booking and deduplicates review requests', function () {
    $deadline = $this->payment->expires_at;
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/recheck')->assertOk()
        ->assertJsonPath('status', 'ACTIVE')->assertJsonPath('verificationStatus', 'AWAITING_EVIDENCE')->assertJsonPath('canRequestReview', true);
    $body = ['reference' => '123456789012', 'note' => 'Bank debited my account.'];
    $id = $this->postJson($this->api.'/request-review', $body)->assertOk()->assertJsonPath('verificationStatus', 'REVIEW_REQUESTED')->assertJsonPath('canRequestReview', false)->json('reviewId');
    $this->postJson($this->api.'/request-review', $body)->assertOk()->assertJsonPath('reviewId', $id);
    $this->postJson($this->api.'/recheck')->assertOk()->assertJsonPath('reviewId', $id);
    app(TenantContext::class)->run($this->merchant->id, function () use ($deadline) {
        expect(SupportTicket::count())->toBe(1)->and(TicketMessage::count())->toBe(1)->and(PaymentObservation::count())->toBe(0)->and(WebhookDelivery::count())->toBe(0);
        expect($this->payment->refresh()->status)->toBe(PaymentStatus::ACTIVE)->and($this->payment->expires_at->equalTo($deadline))->toBeTrue();
        expect(TicketMessage::first()->message)->toContain('Unverified', '123456789012');
    });
});

it('expires overdue processing without reopening it and allows review of the same booking', function () {
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'PROCESSING', 'processing_expires_at' => now()->subSecond()]));
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/recheck')->assertOk()->assertJsonPath('status', 'FAILED')->assertJsonPath('verificationStatus', 'AWAITING_EVIDENCE');
    $this->postJson($this->api.'/request-review')->assertOk()->assertJsonPath('status', 'FAILED')->assertJsonPath('paymentId', $this->payment->id);
    app(TenantContext::class)->run($this->merchant->id, function () {
        expect(PaymentSession::count())->toBe(1)->and($this->account->refresh()->is_slot_busy)->toBeFalse();
        expect(TicketMessage::first()->message)->toContain('(not supplied)');
    });
});

it('does not approve a closed payment from late evidence or a claimed reference', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->payment->update(['status' => 'EXPIRED', 'completed_at' => now()]);
        PaymentObservation::factory()->create(['receiving_account_id' => $this->account->id, 'attributed_payment_id' => $this->payment->id, 'attribution_status' => 'LATE_UNMATCHED']);
    });
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/recheck')->assertOk()->assertJsonPath('status', 'EXPIRED')->assertJsonPath('verificationStatus', 'REVIEW_REQUIRED');
    $this->postJson($this->api.'/request-review', ['reference' => 'CLAIM'])->assertOk()->assertJsonPath('status', 'EXPIRED')->assertJsonPath('verificationStatus', 'REVIEW_REQUESTED');
});

it('reuses an existing late-payment ticket', function () {
    $ticket = app(TenantContext::class)->run($this->merchant->id, fn () => SupportTicket::factory()->create(['payment_id' => $this->payment->id, 'customer_id' => $this->customer->id, 'subject' => '[LATE PAYMENT DETECTED] Order #'.$this->payment->order_id]));
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/request-review')->assertOk()->assertJsonPath('reviewId', $ticket->id);
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(SupportTicket::count())->toBe(1));
});

it('finalizes only eligible stored evidence after its verification grace', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        $observation = PaymentObservation::factory()->create(['receiving_account_id' => $this->account->id, 'attributed_payment_id' => $this->payment->id, 'attribution_status' => 'ATTRIBUTED']);
        $this->payment->update(['attributed_observation_id' => $observation->id, 'verification_level' => 1, 'verification_grace_expires_at' => now()->addSeconds(5)]);
    });
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/request-review')->assertOk()->assertJsonPath('verificationStatus', 'VERIFYING')->assertJsonPath('canRequestReview', false)->assertJsonPath('reviewId', null);
    $this->travel(6)->seconds();
    $this->postJson($this->api.'/recheck')->assertOk()->assertJsonPath('verificationStatus', 'CONFIRMED')->assertJsonPath('status', 'SUCCESS');
    $this->postJson($this->api.'/request-review')->assertOk()->assertJsonPath('verificationStatus', 'CONFIRMED')->assertJsonPath('reviewId', null);
});

it('requires both the checkout device proof and authenticated payment owner', function () {
    $this->postJson($this->checkout.'/recheck')->assertStatus(400);
    $this->withHeaders(['X-Device-Id' => $this->device->id, 'X-Device-Token' => 'device-proof'])->postJson($this->checkout.'/recheck')->assertUnauthorized();
    $this->withToken($this->token)->postJson($this->checkout.'/recheck')->assertOk()->assertJsonPath('verificationStatus', 'AWAITING_EVIDENCE');
    $this->postJson($this->checkout.'/request-review', ['reference' => 'customer-claim'])->assertOk()->assertJsonPath('verificationStatus', 'REVIEW_REQUESTED');
    $this->postJson($this->checkout.'/request-review', ['note' => str_repeat('x', 2001)])->assertUnprocessable();
    $this->withHeader('X-Device-Token', 'bad')->postJson($this->checkout.'/recheck')->assertStatus(409);
});

it('requires a secret merchant key and isolates merchants', function () {
    $this->postJson($this->api.'/recheck')->assertUnauthorized();
    $other = Merchant::factory()->create();
    $pk = 'pk_live_'.str_repeat('p', 64);
    app(TenantContext::class)->run($other->id, fn () => MerchantApiKey::factory()->create(['key_hash' => hash('sha256', 'sk_live_'.str_repeat('z', 64)), 'type' => 'secret', 'mode' => 'live']));
    app(TenantContext::class)->run($this->merchant->id, fn () => MerchantApiKey::factory()->create(['key_hash' => hash('sha256', $pk), 'type' => 'publishable', 'mode' => 'live']));
    $this->withHeader('X-Api-Key', $pk)->postJson($this->api.'/request-review')->assertUnauthorized();
    $this->withHeader('X-Api-Key', 'sk_live_'.str_repeat('z', 64))->postJson($this->api.'/recheck')->assertNotFound();
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/request-review', ['reference' => ['invalid']])->assertUnprocessable();
});

it('rejects unstarted or non-UPI payments and throttles rechecks', function () {
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'INITIATED']));
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/recheck')->assertStatus(400);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'ACTIVE', 'method' => 'COD']));
    $this->postJson($this->api.'/recheck')->assertStatus(400);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['method' => 'UPI']));
    for ($i = 0; $i < 8; $i++) {
        $this->postJson($this->api.'/recheck')->assertOk();
    }
    $this->postJson($this->api.'/recheck')->assertTooManyRequests();
});

it('requires the real payment owner even when a closed checkout no longer enforces its original device', function () {
    $other = Customer::factory()->create();
    $otherToken = 'csess_'.str_repeat('x', 64);
    CustomerSession::factory()->create(['customer_id' => $other->id, 'token_hash' => hash('sha256', $otherToken)]);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'CANCELLED', 'completed_at' => now()]));
    $this->withHeaders(['X-Device-Id' => 'another-browser'])->withToken($otherToken)->postJson($this->checkout.'/request-review')->assertUnauthorized();
    $this->withToken($this->token)->postJson($this->checkout.'/recheck')->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('verificationStatus', 'AWAITING_EVIDENCE');
    $id = $this->postJson($this->checkout.'/request-review')->assertOk()->json('reviewId');
    app(TenantContext::class)->run($this->merchant->id, fn () => SupportTicket::findOrFail($id)->update(['status' => 'RESOLVED']));
    $this->postJson($this->checkout.'/request-review')->assertOk()->assertJsonPath('reviewId', $id)->assertJsonPath('reviewStatus', 'RESOLVED')->assertJsonPath('status', 'CANCELLED');
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(SupportTicket::count())->toBe(1));
});

it('exposes booking and consent APIs only to the merchant and authenticated customer', function () {
    $this->withHeader('X-Api-Key', $this->key)->postJson($this->api.'/notification-preferences', ['enabled' => true])->assertOk()->assertJsonPath('whatsappUpdatesConsent', true);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'SUCCESS']));
    $this->postJson($this->api.'/booking-status', ['bookingStatus' => 'CONFIRMED', 'bookingVersion' => 0])->assertOk()->assertJsonPath('bookingVersion', 1)->assertJsonPath('bookingStatus', 'CONFIRMED');
    $this->withHeader('X-Device-Id', 'customer-browser')->withToken($this->token)->postJson($this->checkout.'/notification-preferences', ['enabled' => false])->assertOk()->assertJsonPath('whatsappUpdatesConsent', false);
});

it('records explicit customer notification consent on a server-created payment', function () {
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['status' => 'EXPIRED']));
    $this->withHeader('X-Api-Key', $this->key)->postJson('/api/v1/payments', [
        'orderId' => 'CONSENT-ORDER', 'amount' => '12.00', 'customerId' => $this->customer->id, 'whatsappUpdatesConsent' => true,
    ])->assertCreated();
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(PaymentSession::where('order_id', 'CONSENT-ORDER')->sole()->whatsapp_updates_consent)->toBeTrue());
});

it('rejects customer notification opt-in using a browser publishable key', function () {
    $key = 'pk_live_'.str_repeat('p', 64);
    app(TenantContext::class)->run($this->merchant->id, fn () => MerchantApiKey::factory()->create(['key_hash' => hash('sha256', $key), 'type' => 'publishable', 'mode' => 'live']));
    $this->merchant->update(['allowed_origins' => ['https://merchant.example']]);
    $this->withHeaders(['X-Api-Key' => $key, 'Origin' => 'https://merchant.example'])->postJson('/api/v1/payments', ['orderId' => 'NO-CONSENT', 'amount' => '12.00', 'whatsappUpdatesConsent' => true])->assertStatus(403);
});
