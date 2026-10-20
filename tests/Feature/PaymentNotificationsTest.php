<?php

declare(strict_types=1);

use App\Domain\Notifications\Jobs\DeliverWhatsappNotification;
use App\Domain\Notifications\Services\PaymentNotifications;
use App\Domain\Payments\Services\BookingService;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use App\Models\WhatsappNotification;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Bus::fake();
    Cache::flush();
    Http::preventStrayRequests();
    config(['paykr.sidecars.whatsapp_enabled' => true, 'paykr.sidecars.whatsapp_url' => 'http://127.0.0.1:3101', 'paykr.internal_token' => 'test-only']);
    $this->merchant = Merchant::factory()->create(['whatsapp_merchant_enabled' => true, 'whatsapp_customer_enabled' => true, 'workflow_webhooks_enabled' => true, 'whatsapp_phone' => '9123456789', 'webhook_url' => 'https://merchant.example/events']);
    $this->customer = Customer::factory()->create(['mobile_number' => '9234567890']);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->payment = PaymentSession::factory()->active()->create(['customer_id' => $this->customer->id, 'whatsapp_phone' => $this->customer->mobile_number, 'whatsapp_updates_consent' => true]);
    });
    $this->notifications = app(PaymentNotifications::class);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('persists deduplicated messages and website events with encrypted private content', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        DB::transaction(function () {
            $this->notifications->record($this->payment, 'ORDER_RECEIVED');
            $this->notifications->record($this->payment, 'ORDER_RECEIVED');
        });
        expect(WhatsappNotification::count())->toBe(2)->and(WebhookDelivery::count())->toBe(1);
        $message = WhatsappNotification::where('audience', 'customer')->sole();
        expect($message->message)->toContain($this->payment->order_id)->and($message->recipient)->toBe('919234567890');
        expect(DB::table('whatsapp_notifications')->where('id', $message->id)->value('recipient'))->not->toContain('9234567890');
        Bus::assertDispatchedTimes(DeliverWhatsappNotification::class, 2);
        foreach (['PAYMENT_VERIFYING', 'PAYMENT_DELAYED', 'PAYMENT_CONFIRMED', 'PAYMENT_CANCELLED', 'PAYMENT_REVIEW_REQUESTED', 'PAYMENT_REVIEW_REQUIRED', 'BOOKING_CONFIRMED', 'BOOKING_REJECTED', 'BOOKING_CANCELLED'] as $event) {
            $this->notifications->record($this->payment, $event);
        }
        $this->payment->update(['method' => 'COD']);
        $this->notifications->record($this->payment, 'PAYMENT_CONFIRMED');
        expect(fn () => $this->notifications->record($this->payment, 'INVALID'))->toThrow(InvalidArgumentException::class);
    });
});

it('sends once and leaves ambiguous delivery for explicit review', function () {
    Http::fake(['*/status' => Http::response(['isConnected' => true]), '*/send' => Http::sequence()->push(['success' => true, 'id' => 'message-id'])->push(['success' => false])]);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->notifications->record($this->payment, 'ORDER_RECEIVED');
        $messages = WhatsappNotification::orderBy('audience')->get();
        (new DeliverWhatsappNotification($this->merchant->id, $messages[0]->id))->handle(app(TenantContext::class), $this->notifications);
        $this->notifications->deliver($messages[0]->id);
        $this->notifications->deliver($messages[1]->id);
        $this->notifications->deliver('missing');
        expect($messages[0]->refresh()->status)->toBe('SENT')->and($messages[0]->provider_id)->toBe('message-id')->and($messages[1]->refresh()->status)->toBe('UNKNOWN');
        Http::assertSentCount(4);
    });
});

it('retries disconnected senders and recovers pending work without duplicate sends', function () {
    Http::fake(['*/status' => Http::response(['isConnected' => false])]);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->notifications->record($this->payment, 'ORDER_RECEIVED');
        $message = WhatsappNotification::first();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->notifications->deliver($message->id);
            if ($attempt === 1) {
                $this->notifications->deliver($message->id);
            }
            $this->travel(151)->seconds();
        }
        expect($message->refresh()->status)->toBe('FAILED')->and($message->attempts)->toBe(5);
    });
    $this->notifications->recover();
    Http::assertSentCount(5);
});

it('suppresses stale alerts, revoked consent, and unapproved recipients', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->notifications->record($this->payment, 'PAYMENT_VERIFYING');
        $this->payment->update(['status' => 'SUCCESS']);
        foreach (WhatsappNotification::get() as $message) {
            $this->notifications->deliver($message->id);
        }
        expect(WhatsappNotification::where('status', 'SKIPPED')->count())->toBe(2);
        $this->payment->update(['whatsapp_updates_consent' => false]);
        $this->merchant->update(['whatsapp_phone' => 'invalid']);
        $this->notifications->record($this->payment, 'PAYMENT_CONFIRMED');
        expect(WhatsappNotification::count())->toBe(2);
        $this->payment->update(['is_test' => true]);
        $this->notifications->record($this->payment, 'BOOKING_CONFIRMED');
        Http::assertNothingSent();
    });
});

it('keeps payment and booking decisions separate and validates version and consent', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        $bookings = app(BookingService::class);
        expect(fn () => $bookings->update($this->payment->id, ['bookingStatus' => 'CONFIRMED', 'bookingVersion' => 0]))->toThrow(ValidationException::class);
        $this->payment->update(['status' => 'SUCCESS']);
        $result = $bookings->update($this->payment->id, ['bookingStatus' => 'CONFIRMED', 'bookingVersion' => 0]);
        expect($result['bookingVersion'])->toBe(1);
        expect($bookings->update($this->payment->id, ['bookingStatus' => 'CONFIRMED', 'bookingVersion' => 0]))->toBe($result);
        expect(fn () => $bookings->update($this->payment->id, ['bookingStatus' => 'CANCELLED', 'bookingVersion' => 0]))->toThrow(ValidationException::class);
        $bookings->update($this->payment->id, ['bookingStatus' => 'CANCELLED', 'bookingVersion' => 1]);
        expect($this->payment->refresh()->status->value)->toBe('SUCCESS');
        $bookings->consent($this->payment->id, false, 'CUSTOMER');
        expect($this->payment->refresh()->whatsapp_phone)->toBeNull();
        $bookings->consent($this->payment->id, true, 'MERCHANT_API_KEY');
        expect($this->payment->refresh()->whatsapp_updates_consent)->toBeTrue();
        $this->payment->update(['customer_id' => null]);
        expect(fn () => $bookings->consent($this->payment->id, true, 'CUSTOMER'))->toThrow(ValidationException::class);
    });
});

it('retains the outbox when enqueue fails', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->notifications->record($this->payment, 'ORDER_RECEIVED');
        expect(WhatsappNotification::where('status', 'PENDING')->count())->toBe(2);
    });
});

it('cancels customer delivery after consent withdrawal and rolls back undelivered events', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        try {
            DB::transaction(function () {
                $this->notifications->record($this->payment, 'ORDER_RECEIVED');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        expect(WhatsappNotification::count())->toBe(0)->and(WebhookDelivery::count())->toBe(0);
        Bus::assertNothingDispatched();
        $this->notifications->record($this->payment, 'ORDER_RECEIVED');
        $message = WhatsappNotification::where('audience', 'customer')->sole();
        app(BookingService::class)->consent($this->payment->id, false, 'CUSTOMER');
        $this->notifications->deliver($message->id);
        expect($message->refresh()->status)->toBe('SKIPPED');
        Http::assertNothingSent();
    });
});
