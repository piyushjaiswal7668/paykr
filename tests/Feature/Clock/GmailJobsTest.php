<?php

declare(strict_types=1);

use App\Domain\Observers\Jobs\PollGmail;
use App\Domain\Observers\Jobs\PollGmailNow;
use App\Domain\Observers\Jobs\SweepMessages;
use App\Domain\Observers\Services\GmailPoller;
use App\Domain\Observers\Services\ObserverDispatch;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    $this->freezeTime();
    config(['cache.default' => 'array']);
    Bus::fake();
    Cache::flush();
    Http::preventStrayRequests();
});
afterEach(fn () => RefreshDatabaseState::$migrated = false);

it('refreshes merchant Gmail credentials and deduplicates messages through the real verification pipeline', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        $connection = MerchantGmailConnection::factory()->create(['refresh_token' => 'private-refresh', 'access_token' => null, 'token_expires_at' => null]);
        $account = PaymentReceivingAccount::factory()->active()->create();
        $payment = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000]);
        app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
        config(['paykr.payments.grace_period_ms' => 0]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-access', 'expires_in' => 3600]),
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'mail1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/mail1?*' => Http::response(['internalDate' => (string) now()->getTimestampMs(), 'payload' => [
                'headers' => [['name' => 'From', 'value' => 'alerts@retail.bank.in'], ['name' => 'Subject', 'value' => 'Credit alert']],
                'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Rs 200 credited UTR: 123456789012'), '+/', '-_'), '=')]]],
            ]]),
        ]);
        app()->call([new PollGmail($merchant->id), 'handle']);
        expect($payment->refresh()->status->value)->toBe('SUCCESS');
        expect($connection->refresh()->access_token)->toBe('private-access');
        expect($connection->last_successful_fetch)->not->toBeNull();
        expect(DB::table('gmail_processed_messages')->where('merchant_id', $merchant->id)->count())->toBe(1);
        expect(PaymentObservation::count())->toBe(1);
        app()->call([new PollGmailNow($merchant->id), 'handle']);
        Http::assertSentCount(3);
        $this->travel(2)->seconds();
        app()->call([new PollGmailNow($merchant->id), 'handle']);
        Http::assertSentCount(4);
        expect(PaymentObservation::count())->toBe(1);
        app()->call([new PollGmail('other-merchant'), 'handle']);
        Http::assertSentCount(4);
    });
});

it('degrades on Gmail failures without declaring a successful poll or leaking tokens', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $connection = MerchantGmailConnection::factory()->create(['refresh_token' => 'private-refresh', 'access_token' => null, 'token_expires_at' => null, 'last_successful_fetch' => null]);
        Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'secret' => 'must-not-leak'], 401)]);
        expect(fn () => app(GmailPoller::class)->poll())->toThrow(ValidationException::class, 'GMAIL_OAUTH_FAILED');
        expect($connection->refresh()->last_successful_fetch)->toBeNull();
        $this->travel(2)->seconds();
        $connection->update(['access_token' => 'access', 'token_expires_at' => now()->addHour()]);
        expect(fn () => app(GmailPoller::class)->poll())->toThrow(ValidationException::class, 'SERVICE_UNAVAILABLE');
        expect(DB::table('gmail_processed_messages')->count())->toBe(0);
    });
});

it('throttles burst and idle observer dispatch independently and uses the authenticated merchant sidecar path', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh']);
        config(['paykr.sidecars.messages_enabled' => true, 'paykr.internal_token' => 'test-internal']);
        $dispatch = app(ObserverDispatch::class);
        $dispatch->dispatch($merchant->id, null);
        $dispatch->dispatch($merchant->id, null);
        $dispatch->dispatch($merchant->id, 'account', true);
        $dispatch->dispatch($merchant->id, 'account', true);
        Bus::assertDispatchedTimes(PollGmail::class, 1);
        Bus::assertDispatchedTimes(PollGmailNow::class, 1);
        $this->travel(2)->seconds();
        $dispatch->dispatch($merchant->id, 'account', true);
        Bus::assertDispatchedTimes(PollGmailNow::class, 2);
        Http::fake(['*' => Http::response([], 200)]);
        app()->call([new SweepMessages($merchant->id), 'handle']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/profiles/'.$merchant->id.'/sweep') && $request->hasHeader('X-Paykr-Internal-Token', 'test-internal'));
        config(['paykr.sidecars.messages_enabled' => false]);
        app()->call([new SweepMessages($merchant->id), 'handle']);
        Http::assertSentCount(1);
    });
});

it('does not poll Gmail while another worker owns the merchant lock', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh']);
        $lock = Cache::lock('paykr:gmail:poll:'.$merchant->id, 90);
        expect($lock->get())->toBeTrue();
        try {
            app(GmailPoller::class)->poll();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    });
});

it('rejects malformed Gmail list and message responses without acknowledging mail', function (array $list, array $message) {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($list, $message) {
        $connection = MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh', 'access_token' => 'access', 'token_expires_at' => now()->addHour(), 'last_successful_fetch' => null]);
        PaymentReceivingAccount::factory()->active()->create();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response($list),
            'gmail.googleapis.com/gmail/v1/users/me/messages/mail1?*' => Http::response($message),
        ]);
        expect(fn () => app(GmailPoller::class)->poll())->toThrow(ValidationException::class, 'SERVICE_UNAVAILABLE');
        expect($connection->fresh()->last_successful_fetch)->toBeNull();
        expect(DB::table('gmail_processed_messages')->count())->toBe(0);
    });
})->with([
    [['messages' => 'invalid'], []],
    [['messages' => [['id' => 'mail1']]], ['internalDate' => 'invalid', 'payload' => []]],
]);

it('maps Gmail transport failures to a retryable service error', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh', 'access_token' => 'access', 'token_expires_at' => now()->addHour()]);
        Http::fake(fn () => throw new ConnectionException('offline'));
        expect(fn () => app(GmailPoller::class)->poll())->toThrow(ValidationException::class, 'SERVICE_UNAVAILABLE');
    });
});

it('extracts multipart Gmail fallback bodies while ignoring malformed parts', function (string $mime, string $body) {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($mime, $body) {
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh', 'access_token' => 'access', 'token_expires_at' => now()->addHour()]);
        PaymentReceivingAccount::factory()->active()->create();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'mail1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/mail1?*' => Http::response(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['parts' => ['malformed', ['mimeType' => $mime, 'body' => ['data' => base64_encode($body)]]]]]),
        ]);
        app(GmailPoller::class)->poll();
        expect(DB::table('gmail_processed_messages')->count())->toBe(1);
    });
})->with([['text/html', '<p>Unrelated email &amp; details</p>'], ['application/octet-stream', 'Unrelated email']]);

it('requires refresh credentials and rejects empty access tokens returned by Gmail', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $connection = MerchantGmailConnection::factory()->create(['refresh_token' => null]);
        expect(fn () => app(GmailPoller::class)->accessToken($connection))->toThrow(ValidationException::class, 'GMAIL_NOT_CONFIGURED');
        $connection->update(['refresh_token' => 'refresh', 'access_token' => null]);
        Http::fake(['*' => Http::response(['access_token' => '', 'expires_in' => 3600])]);
        expect(fn () => app(GmailPoller::class)->accessToken($connection))->toThrow(ValidationException::class, 'GMAIL_OAUTH_FAILED');
    });
});

it('rechecks processed receipts after fetching a message another worker acknowledged', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        MerchantGmailConnection::factory()->create(['refresh_token' => 'refresh', 'access_token' => 'access', 'token_expires_at' => now()->addHour()]);
        PaymentReceivingAccount::factory()->active()->create();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'mail1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/mail1?*' => function () use ($merchant) {
                DB::table('gmail_processed_messages')->insert(['merchant_id' => $merchant->id, 'message_id' => 'mail1', 'processed_at' => now()]);

                return Http::response(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Rs 200 credited UTR 123456789012')]]]);
            },
        ]);
        app(GmailPoller::class)->poll();
        expect(PaymentObservation::count())->toBe(0);
        expect(DB::table('gmail_processed_messages')->count())->toBe(1);
    });
});
