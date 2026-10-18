<?php

declare(strict_types=1);

use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentCancelled;
use App\Domain\Payments\Events\PaymentMethodSwitched;
use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Events\QueueUpdated;
use App\Domain\Payments\Events\SessionActivated;
use App\Domain\Realtime\Events\RealtimeUpdate;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Events\PaymentFraudBlocked;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
});

it('requires bound active device proof even for terminal payments and never binds during auth', function () {
    $merchant = Merchant::factory()->create();
    $device = Device::factory()->create(['device_token_hash' => hash('sha256', 'device-proof')]);
    $payment = app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->create(['device_id' => $device->id, 'status' => 'SUCCESS']));
    $body = ['socket_id' => '12.34', 'channel_name' => 'private-payment.'.$payment->id];
    $this->postJson('/broadcasting/auth', $body)->assertForbidden();
    $this->withHeaders(['X-Device-Id' => $device->id, 'X-Device-Token' => 'wrong'])->postJson('/broadcasting/auth', $body)->assertForbidden();
    $this->withHeaders(['X-Device-Token' => 'device-proof'])->postJson('/broadcasting/auth', $body)->assertOk()->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', '12.34:'.$body['channel_name'], 'test-secret')]);
    $device->update(['status' => DeviceStatus::REVOKED]);
    $this->postJson('/broadcasting/auth', $body)->assertForbidden();
    $this->postJson('/broadcasting/auth', ['socket_id' => 'invalid', 'channel_name' => $body['channel_name']])->assertUnprocessable();
    expect(app(TenantContext::class)->run($merchant->id, fn () => $payment->fresh()->device_id))->toBe($device->id);
});

it('authorizes customer tokens only for their bound payment and supports state fallback', function () {
    $session = CustomerSession::factory()->create(['token_hash' => hash('sha256', 'customer-proof')]);
    $merchant = Merchant::factory()->create();
    $payment = app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->active()->create(['customer_id' => $session->customer_id, 'device_id' => Device::factory()]));
    $body = ['socket_id' => '1.2', 'channel_name' => 'private-payment.'.$payment->id];
    $this->withToken('customer-proof')->postJson('/broadcasting/auth', $body)->assertOk();
    $this->getJson('/api/v1/checkout/'.$payment->id.'/state')->assertOk()->assertJsonPath('status', 'ACTIVE');
    $session->update(['is_active' => false]);
    $this->postJson('/broadcasting/auth', $body)->assertForbidden();
    $this->getJson('/api/v1/checkout/'.$payment->id.'/state')->assertStatus(409);
});

it('isolates merchant channels by active tenant membership', function () {
    $user = User::factory()->create();
    $mine = Merchant::factory()->create();
    $other = Merchant::factory()->create();
    $user->merchants()->attach($mine, ['role' => 'viewer']);
    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-merchant.'.$mine->id.'.live'])->assertOk();
    foreach (['private-merchant.'.$other->id.'.live', 'merchant.'.$mine->id.'.live', 'presence-merchant.'.$mine->id.'.live'] as $channel) {
        $this->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertForbidden();
    }
    $user->merchants()->detach($mine);
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-merchant.'.$mine->id.'.live'])->assertForbidden();
});

it('broadcasts each realtime topic with allowlisted private payloads only after commit', function (string $kind, string $expected) {
    Event::fake([RealtimeUpdate::class]);
    config(['broadcasting.default' => 'log']);
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($kind, $expected, $merchant) {
        $payment = PaymentSession::factory()->active()->create(['method' => $kind === 'counter' ? 'CASH_COUNTER' : 'UPI', 'metadata' => ['secret' => 'never-broadcast']]);
        $event = match ($kind) {
            'blocked' => new PaymentFraudBlocked($merchant->id, $payment->id, 'customer'),
            'promoted' => new SessionActivated($merchant->id, $payment->id, null, PaymentStatus::QUEUED, PaymentStatus::ACTIVE),
            'counter' => new PaymentMethodSwitched($merchant->id, $payment->id, PaymentMethod::UPI, PaymentMethod::CASH_COUNTER),
            'queue' => new QueueUpdated($merchant->id, 'account', [$payment->id => 1]),
            default => new PaymentStateChanged($merchant->id, $payment->id, null, PaymentStatus::INITIATED, PaymentStatus::ACTIVE),
        };
        DB::beginTransaction();
        event($event);
        Event::assertNotDispatched(RealtimeUpdate::class);
        DB::rollBack();
        Event::assertNotDispatched(RealtimeUpdate::class);
        DB::transaction(fn () => event($event));
        Event::assertDispatched(RealtimeUpdate::class, fn ($update) => $update->broadcastAs() === $expected);
        Event::assertDispatched(RealtimeUpdate::class, function ($update) use ($payment) {
            if ($update->broadcastAs() !== 'PaymentStatusChanged') {
                return false;
            }
            expect($update->broadcastQueue())->toBe('payments');
            expect($update->broadcastOn()->name)->toBe('private-payment.'.$payment->id);
            expect(array_keys($update->broadcastWith()))->toBe(['status', 'queuePosition', 'expiresAt', 'processingExpiresAt', 'method', 'timeRemainingSeconds', 'verificationLevel', 'verificationTag', 'sources', 'reason', 'message', 'blockedNotice']);
            expect(json_encode($update->broadcastWith()))->not->toContain('never-broadcast');

            return true;
        });
    });
})->with([
    ['status', 'PaymentStatusChanged'], ['merchant', 'LivePaymentUpdated'], ['blocked', 'PaymentAccountBlocked'],
    ['promoted', 'QueuePromoted'], ['counter', 'CounterPaymentRequested'], ['queue', 'PaymentStatusChanged'],
]);

it('rejects unknown and unbound payment channels without creating device records', function () {
    $merchant = Merchant::factory()->create();
    $payment = app(TenantContext::class)->run($merchant->id, fn () => PaymentSession::factory()->create());
    foreach ([$payment->id, (string) Str::uuid()] as $id) {
        $this->withHeaders(['X-Device-Id' => 'unregistered-device', 'X-Device-Token' => 'fake'])->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-payment.'.$id])->assertForbidden();
    }
    expect(Device::count())->toBe(0);
});

it('keeps a committed transition successful when broadcasting cannot be queued', function () {
    Queue::fake();
    config(['broadcasting.default' => 'log']);
    Event::listen(RealtimeUpdate::class, fn () => throw new RuntimeException('simulated queue failure'));
    Log::shouldReceive('warning')->once()->with('Realtime update could not be queued.', Mockery::on(fn ($context) => $context['exception'] === RuntimeException::class));
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        $payment = PaymentSession::factory()->active()->create();
        DB::transaction(function () use ($payment, $merchant) {
            $payment->update(['status' => PaymentStatus::CANCELLED]);
            event(new PaymentCancelled($merchant->id, $payment->id, null, PaymentStatus::ACTIVE, PaymentStatus::CANCELLED));
        });
        expect($payment->refresh()->status)->toBe(PaymentStatus::CANCELLED);
    });
});
