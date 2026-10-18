<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Webhooks\Services\WebhookDnsResolver;
use App\Domain\Webhooks\Services\WebhookRecoveryService;
use App\Domain\Webhooks\Services\WebhookService;
use App\Domain\Webhooks\Services\WebhookUrlPolicy;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Bus::fake([DeliverWebhook::class]);
    Http::preventStrayRequests();
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function withinWebhookMerchant(Closure $test): void
{
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook', 'webhook_secret' => 'test-signing-secret']);
    app(TenantContext::class)->run($merchant->id, fn () => $test($merchant, app(WebhookService::class), app(PaymentTransitionService::class), new Actor(ActorType::SYSTEM)));
}

it('successfully dispatches HMAC-SHA256 signed webhook on payment event', function () {
    $this->travelTo(now()->startOfSecond());
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        Http::fake(function (Request $request, array $options) {
            expect($options['timeout'])->toBe(10);
            expect($options['connect_timeout'])->toBe(5);
            expect($options['allow_redirects'])->toBeFalse();
            expect($options['proxy'])->toBe('');

            return Http::response('received', 201);
        });
        $account = PaymentReceivingAccount::factory()->active()->create();
        $observation = PaymentObservation::factory()->create(['receiving_account_id' => $account->id, 'bank_reference_number' => '123456789012']);
        $payment = PaymentSession::factory()->create(['method' => 'COD', 'order_id' => 'HOOK-ORD-001', 'amount_paise' => 20000, 'attributed_observation_id' => $observation->id, 'metadata' => ['name' => 'हिन्दी / test']]);
        $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
        DB::transaction(function () use ($payment, $transitions, $actor) {
            $transitions->transition($payment->id, PaymentStatus::SUCCESS, $actor);
            expect(WebhookDelivery::count())->toBe(1);
            Bus::assertNothingDispatched();
        });
        $delivery = WebhookDelivery::sole();
        Bus::assertDispatched(DeliverWebhook::class, fn ($job) => $job->merchantId === $merchant->id && $job->deliveryId === $delivery->id && $job->connection === 'redis');
        $payload = json_decode($delivery->payload, true, 512, JSON_THROW_ON_ERROR);
        expect(array_keys($payload))->toBe(['event', 'paymentId', 'orderId', 'amount', 'amountPaise', 'currency', 'method', 'utr', 'completedAt', 'metadata']);
        expect($payload['amount'])->toBe('200.00')->and($payload['amountPaise'])->toBe(20000);
        expect($payload['utr'])->toBe('123456789012')->and($payload['completedAt'])->toBe(now()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        expect($delivery->payload)->toContain('हिन्दी / test');
        expect($delivery->attempt_count)->toBe(0);
        (new DeliverWebhook($merchant->id, $delivery->id))->handle(app(TenantContext::class), $webhooks);
        Http::assertSent(fn (Request $request) => $request->body() === $delivery->payload
            && $request->hasHeader('X-Gateway-Signature', 'sha256='.hash_hmac('sha256', $delivery->payload, 'test-signing-secret'))
            && $request->hasHeader('X-Gateway-Event', 'SUCCESS') && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('X-Gateway-Delivery-Id', $delivery->id)
            && $request->hasHeader('X-Gateway-Timestamp', (string) now()->getTimestampMs()));
        expect($delivery->refresh()->is_successful)->toBeTrue()->and($delivery->response_status)->toBe(201);
        expect($delivery->response_body)->toBe('received')->and($delivery->next_retry_at)->toBeNull();
        $webhooks->deliver($delivery->id);
        expect($webhooks->recordTerminal($payment->id)->id)->toBe($delivery->id);
        Http::assertSentCount(1);
        expect(WebhookDelivery::count())->toBe(1);
    });
});

it('records failure and supports retry on merchant HTTP 500 error', function () {
    $this->travelTo(now()->startOfSecond());
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        Http::fake(['*' => Http::sequence()->push(str_repeat('ह', 1200), 500)->push('ok', 200)]);
        $payment = PaymentSession::factory()->create(['method' => 'CASH_COUNTER']);
        $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
        $transitions->transition($payment->id, PaymentStatus::FAILED, $actor);
        $delivery = WebhookDelivery::sole();
        $raw = $delivery->payload;
        expect($raw)->toContain('"metadata":{}');
        $webhooks->deliver($delivery->id);
        expect($delivery->refresh()->attempt_count)->toBe(1);
        expect(mb_strlen($delivery->response_body))->toBe(1000);
        expect($delivery->next_retry_at->equalTo(now()->addSeconds(5)))->toBeTrue();
        $merchant->update(['webhook_url' => 'https://different.example/hook']);
        $payment->update(['metadata' => ['changed' => true]]);
        $webhooks->deliver($delivery->id);
        Http::assertSentCount(1);
        $this->travel(5)->seconds();
        $webhooks->deliver($delivery->id);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request,$response]) {
            expect($request->body())->toBe($raw);
            expect($request->url())->toBe('https://merchant.example/hook');
            expect($request->header('X-Gateway-Delivery-Id'))->toBe([$delivery->id]);
        }
        expect($delivery->refresh()->is_successful)->toBeTrue()->and($delivery->attempt_count)->toBe(2);
    });
});

it('bounds retry attempts and uses persisted backoff rather than queue attempt numbers', function () {
    $this->travelTo(now()->startOfSecond());
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        Http::fake(['*' => Http::failedConnection()]);
        $payment = PaymentSession::factory()->create();
        $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        $delivery = WebhookDelivery::sole();
        foreach ([5, 30, 120, 600, null] as $index => $delay) {
            $webhooks->deliver($delivery->id);
            expect($delivery->refresh()->attempt_count)->toBe($index + 1);
            expect($delivery->response_body)->toBe('Webhook connection failed or timed out.');
            if ($delay !== null) {
                expect($delivery->next_retry_at->equalTo(now()->addSeconds($delay)))->toBeTrue();
                $this->travel($delay)->seconds();
            } else {
                expect($delivery->next_retry_at)->toBeNull();
            }
        }
        $webhooks->deliver($delivery->id);
        expect($delivery->refresh()->attempt_count)->toBe(5);
        expect(app(WebhookRecoveryService::class)->dispatchDue())->toBe(0);
    });
});

it('creates one durable webhook for each terminal transition and none on rollback', function () {
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        foreach ([PaymentStatus::SUCCESS, PaymentStatus::FAILED, PaymentStatus::EXPIRED, PaymentStatus::CANCELLED] as $status) {
            $payment = PaymentSession::factory()->create(['method' => 'CASH_COUNTER']);
            $transitions->transition($payment->id, PaymentStatus::ACTIVE, $actor);
            $transitions->transition($payment->id, $status, $actor);
            expect(fn () => $transitions->transition($payment->id, $status, $actor))->toThrow(ValidationException::class);
            expect(WebhookDelivery::where('payment_id', $payment->id)->count())->toBe(1);
        }
        expect(WebhookDelivery::count())->toBe(4);
        Bus::assertDispatchedTimes(DeliverWebhook::class, 4);
        Bus::fake([DeliverWebhook::class]);
        $payment = PaymentSession::factory()->create();
        expect(fn () => DB::transaction(function () use ($payment, $transitions, $actor) {
            $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
            throw new RuntimeException('rollback');
        }))->toThrow(RuntimeException::class);
        expect($payment->refresh()->status)->toBe(PaymentStatus::INITIATED);
        expect(WebhookDelivery::where('payment_id', $payment->id)->count())->toBe(0);
        Bus::assertNothingDispatched();
    });
});

it('recovers committed deliveries after Redis dispatch failure without losing the payment outcome', function () {
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        $originalBus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
        Log::shouldReceive('warning')->once()->with('Webhook enqueue failed; persisted delivery awaits recovery.', Mockery::type('array'));
        $payment = PaymentSession::factory()->create();
        $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        expect($payment->refresh()->status)->toBe(PaymentStatus::CANCELLED);
        expect(WebhookDelivery::sole()->attempt_count)->toBe(0);
        Bus::swap($originalBus);
        Bus::fake([DeliverWebhook::class]);
        $this->artisan('paykr:webhooks:retry-pending')->expectsOutput('Due deliveries considered: 1')->assertSuccessful();
        Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
        expect(app(TenantContext::class)->merchantId())->toBe($merchant->id);
    });
});

it('enforces tenant context on jobs and rejects nonterminal webhook creation', function () {
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        Http::fake(['*' => Http::response('ok')]);
        expect(fn () => $webhooks->recordTerminal('missing'))->toThrow(ValidationException::class);
        $payment = PaymentSession::factory()->create();
        expect(fn () => $webhooks->recordTerminal($payment->id))->toThrow(ValidationException::class);
        $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        $delivery = WebhookDelivery::sole();
        $other = Merchant::factory()->create();
        (new DeliverWebhook($other->id, $delivery->id))->handle(app(TenantContext::class), $webhooks);
        Http::assertNothingSent();
        expect(app(TenantContext::class)->merchantId())->toBe($merchant->id);
        expect($delivery->refresh()->attempt_count)->toBe(0);
    });
});

it('blocks unsafe production endpoints and pins public DNS answers without redirects or proxies', function () {
    $dns = Mockery::mock(WebhookDnsResolver::class);
    $dns->shouldReceive('addresses')->with('public.example')->andReturn(['8.8.8.8', '1.1.1.1']);
    $dns->shouldReceive('addresses')->with('mixed.example')->andReturn(['8.8.8.8', '127.0.0.1']);
    $dns->shouldReceive('addresses')->with('missing.example')->andReturn([]);
    $dns->shouldReceive('addresses')->with('ipv6.example')->andReturn(['2606:4700:4700::1111']);
    $policy = new WebhookUrlPolicy($dns);
    expect($policy->requestOptions('http://localhost:9000/hook')['allow_redirects'])->toBeFalse();
    foreach (['file:///tmp/test', 'https://user:pass@example.com/hook', 'https://example.com/#fragment', 'not-a-url'] as $url) {
        expect(fn () => $policy->requestOptions($url))->toThrow(ValidationException::class);
    }
    app()->instance('env', 'production');
    try {
        foreach (['http://public.example/hook', 'https://mixed.example/hook', 'https://missing.example/hook'] as $url) {
            expect(fn () => $policy->requestOptions($url))->toThrow(ValidationException::class);
        }
        $options = $policy->requestOptions('https://public.example:8443/hook');
        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['public.example:8443:8.8.8.8']);
        expect($options['curl'][CURLOPT_PROXY])->toBe('')->and($options['allow_redirects'])->toBeFalse();
        expect($policy->requestOptions('https://ipv6.example/hook')['curl'][CURLOPT_RESOLVE])->toBe(['ipv6.example:443:[2606:4700:4700::1111]']);
        $realPolicy = new WebhookUrlPolicy(new WebhookDnsResolver);
        foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '192.168.1.1', '172.16.0.1', '100.64.0.1', '224.0.0.1', '[::1]', '[fc00::1]', '[fe80::1]', '[::ffff:127.0.0.1]', '[ff02::1]'] as $host) {
            expect(fn () => $realPolicy->requestOptions('https://'.$host.'/hook'))->toThrow(ValidationException::class);
        }
    } finally {
        app()->instance('env', 'testing');
    }
});

it('stores a safe failure for blocked endpoints and missing secrets without an HTTP call', function () {
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        $merchant->update(['webhook_secret' => null]);
        $payment = PaymentSession::factory()->create();
        $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        $delivery = WebhookDelivery::sole();
        $webhooks->deliver($delivery->id);
        expect($delivery->refresh()->attempt_count)->toBe(1);
        expect($delivery->response_body)->toBe('Webhook endpoint or signing configuration is not allowed.');
        Http::assertNothingSent();
    });
});

it('rounds Redis availability up to avoid dropping retries before a millisecond deadline', function () {
    $this->travelTo(Carbon::parse('2026-10-18T10:00:00.123Z'));
    withinWebhookMerchant(function ($merchant, $webhooks, $transitions, $actor) {
        $payment = PaymentSession::factory()->create();
        $transitions->transition($payment->id, PaymentStatus::CANCELLED, $actor);
        $delivery = WebhookDelivery::sole();
        Bus::assertDispatched(DeliverWebhook::class, fn ($job) => $job->delay->format('H:i:s.v') === '10:00:01.000' && $job->delay->greaterThanOrEqualTo($delivery->next_retry_at));
        Http::fake(['*' => Http::response('failed', 500)]);
        $webhooks->deliver($delivery->id);
        $delivery->refresh();
        expect($delivery->next_retry_at->format('H:i:s.v'))->toBe('10:00:05.123');
        Bus::assertDispatched(DeliverWebhook::class, fn ($job) => $job->delay->format('H:i:s.v') === '10:00:06.000');
    });
});
