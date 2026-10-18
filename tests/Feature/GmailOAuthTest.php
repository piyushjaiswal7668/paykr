<?php

declare(strict_types=1);
use App\Domain\Observers\Jobs\PollGmail;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
beforeEach(function () {
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    config(['paykr.gmail.client_id' => 'client-id', 'paykr.gmail.client_secret' => 'client-secret', 'paykr.gmail.redirect_uri' => 'http://localhost/merchant/gmail/callback', 'paykr.payments.grace_period_ms' => 0]);
    $this->merchant = Merchant::factory()->create();
    $this->owner = User::factory()->create();
    $this->merchant->users()->attach($this->owner, ['role' => 'owner']);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ACCESS-PRIVATE', 'refresh_token' => 'REFRESH-PRIVATE', 'expires_in' => 3600]),
        'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'store@example.com']),
        'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'mail-1']]]),
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/mail-1?*' => Http::response(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => 'alerts@retail.bank.in'], ['name' => 'Subject', 'value' => 'Credit alert']], 'body' => ['data' => rtrim(strtr(base64_encode('Rs 200 credited UTR 123456789012'), '+/', '-_'), '=')]]]),
    ]);
});
function gmailState($response): string
{
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}
it('connects Gmail with single-use user-bound state, encrypts tokens, and verifies a real pipeline credit', function () {
    $start = $this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/connect?merchantId='.$this->merchant->id)->assertRedirect();
    $state = gmailState($start);
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'google-code']))->assertOk()->assertSee('Gmail connected')->assertDontSee('ACCESS-PRIVATE')->assertDontSee('REFRESH-PRIVATE')->assertDontSee('google-code');
    app(TenantContext::class)->run($this->merchant->id, function () {
        $connection = MerchantGmailConnection::firstOrFail();
        expect($connection->refresh_token)->toBe('REFRESH-PRIVATE');
        expect(DB::table('merchant_gmail_connections')->where('id', $connection->id)->value('refresh_token'))->not->toBe('REFRESH-PRIVATE');
        $this->account = PaymentReceivingAccount::factory()->active()->create();
        $this->payment = PaymentSession::factory()->forAccount($this->account)->active()->create(['amount_paise' => 20000]);
        $this->account->update(['is_slot_busy' => true, 'current_active_session_id' => $this->payment->id]);
    });
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'again']))->assertStatus(400)->assertSee('could not be completed');
    app()->call([new PollGmail($this->merchant->id), 'handle']);
    app(TenantContext::class)->run($this->merchant->id, fn () => expect($this->payment->refresh()->status->value)->toBe('SUCCESS'));
    $this->postJson('/merchant/gmail/test', ['merchantId' => $this->merchant->id])->assertOk()->assertJsonPath('data.connectedEmail', 'store@example.com')->assertDontSee('ACCESS-PRIVATE');
    $this->postJson('/merchant/gmail/disconnect', ['merchantId' => $this->merchant->id])->assertOk()->assertJsonPath('data.status', 'DISCONNECTED');
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(MerchantGmailConnection::first()->refresh_token)->toBeNull());
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'REFRESH-PRIVATE');
});
it('rejects forged, expired, wrong-user and cross-merchant OAuth state without leaking provider errors', function () {
    $this->getJson('/merchant/gmail/connect?merchantId='.$this->merchant->id)->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/merchant/gmail/connect?merchantId='.$this->merchant->id)->assertForbidden();
    $state = gmailState($this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/connect?merchantId='.$this->merchant->id)->assertRedirect());
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'sanctum')->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertStatus(400);
    $this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/callback?state=forged&code=SECRET')->assertStatus(400)->assertDontSee('SECRET');
    $this->travel(11)->minutes();
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertStatus(400);
    Http::assertNothingSent();
});
it('handles consent denial and missing Gmail configuration safely', function () {
    $state = gmailState($this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/connect?merchantId='.$this->merchant->id));
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))->assertOk()->assertSee('Connection cancelled');
    $this->postJson('/merchant/gmail/test', ['merchantId' => $this->merchant->id])->assertStatus(422);
    $this->postJson('/merchant/gmail/disconnect', ['merchantId' => $this->merchant->id])->assertOk();
    config(['paykr.gmail.client_id' => null, 'paykr.gmail.client_secret' => null]);
    $this->getJson('/merchant/gmail/connect?merchantId='.$this->merchant->id)->assertStatus(422);
    Http::assertNothingSent();
});

it('renders safe OAuth failures and preserves credentials when revocation fails', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'PRIVATE-PROVIDER-ERROR'], 503), 'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'PRIVATE-PROVIDER-ERROR'], 503)]);
    $state = gmailState($this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/connect?merchantId='.$this->merchant->id));
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'PRIVATE-CODE']))->assertStatus(400)->assertDontSee('PRIVATE-PROVIDER-ERROR')->assertDontSee('PRIVATE-CODE');
    app(TenantContext::class)->run($this->merchant->id, fn () => MerchantGmailConnection::factory()->create(['refresh_token' => 'keep-private-refresh']));
    $this->postJson('/merchant/gmail/disconnect', ['merchantId' => $this->merchant->id])->assertStatus(503)->assertDontSee('keep-private-refresh');
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(MerchantGmailConnection::first()->refresh_token)->toBe('keep-private-refresh'));
    $this->get('/merchant/gmail/callback?code=PRIVATE-CODE')->assertStatus(400)->assertDontSee('PRIVATE-CODE');
});

it('handles unavailable Google and serializes OAuth mutations with the Gmail poller', function () {
    $this->actingAs($this->owner, 'sanctum');
    $lock = Cache::lock('paykr:gmail:poll:'.$this->merchant->id, 90);
    expect($lock->get())->toBeTrue();
    try {
        $this->postJson('/merchant/gmail/test', ['merchantId' => $this->merchant->id])->assertStatus(429);
    } finally {
        $lock->release();
    }
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection()]);
    $state = gmailState($this->get('/merchant/gmail/connect?merchantId='.$this->merchant->id));
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'private-code']))->assertStatus(503)->assertDontSee('private-code');
});

it('clears old-account message receipts on reconnect and never returns OAuth tokens in JSON', function () {
    app(TenantContext::class)->run($this->merchant->id, function () {
        MerchantGmailConnection::factory()->create(['client_id' => 'client-id', 'client_secret' => 'client-secret', 'connected_email' => 'previous@example.com']);
        DB::table('gmail_processed_messages')->insert(['merchant_id' => $this->merchant->id, 'message_id' => 'old-message', 'processed_at' => now()]);
    });
    $state = gmailState($this->actingAs($this->owner, 'sanctum')->get('/merchant/gmail/connect?merchantId='.$this->merchant->id));
    $this->get('/merchant/gmail/callback?'.http_build_query(['state' => $state, 'code' => 'private-code']))->assertOk();
    expect(DB::table('gmail_processed_messages')->where('merchant_id', $this->merchant->id)->count())->toBe(0);
    $response = $this->postJson('/merchant/gmail/test', ['merchantId' => $this->merchant->id])->assertOk();
    expect($response->getContent())->not->toContain('access_token', 'refresh_token', 'client_secret', 'ACCESS-PRIVATE', 'REFRESH-PRIVATE', 'client-secret');
});
