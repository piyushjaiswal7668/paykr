<?php

declare(strict_types=1);
use App\Domain\Observers\Data\ObserverRequest;
use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Observers\Services\InternalObserver;
use App\Domain\Observers\Services\ObserverIntake;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Http\Responses\ApiExceptionRenderer;
use App\Models\AndroidDevice;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\ObserverPairingToken;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);
beforeEach(function () {
    Bus::fake();
    Cache::flush();
    Http::preventStrayRequests();
    config(['paykr.payments.grace_period_ms' => 0, 'paykr.internal_token' => 'test-internal-observer-secret']);
    $this->merchant = Merchant::factory()->create();
    $this->admin = User::factory()->create(['is_platform_admin' => true]);
    app(TenantContext::class)->run($this->merchant->id, function () {
        $this->account = PaymentReceivingAccount::factory()->active()->create();
        $this->payment = PaymentSession::factory()->forAccount($this->account)->active()->create(['amount_paise' => 20000]);
        $this->account->update(['is_slot_busy' => true, 'current_active_session_id' => $this->payment->id]);
    });
    $this->qr = app(AndroidObserver::class)->issue($this->merchant->id, $this->admin->id)->data;
});

it('replays the Android driver pairing heartbeat and exact signed JSON through the real pipeline', function () {
    $pair = $this->postJson('/api/v1/pollers/android/pair', ['enrollmentToken' => $this->qr['enrollmentToken'], 'deviceName' => 'Pixel 8 Pro', 'deviceModel' => 'Google Pixel', 'merchantId' => $this->merchant->id])->assertOk()->assertJsonPath('paired', true);
    $process = new Process(['node', base_path('tests/Support/android-driver-replay.mjs')]);
    $process->setInput(json_encode(['deviceId' => $pair->json('deviceId'), 'signingKey' => $pair->json('signingKey'), 'merchantId' => $this->merchant->id, 'enrollmentToken' => $this->qr['enrollmentToken'], 'accountId' => $this->account->id, 'observedAt' => now()->toISOString(), 'timestamp' => now()->getTimestampMs()], JSON_THROW_ON_ERROR));
    $process->mustRun();
    $requests = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    $this->postJson('/api/v1/pollers/android/pair', json_decode($requests[0]['body'], true))->assertUnauthorized();
    $this->withHeader('X-Device-Id', $pair->json('deviceId'))->postJson('/api/v1/pollers/android/heartbeat', json_decode($requests[1]['body'], true))->assertOk()->assertJsonPath('status', 'HEARTBEAT_ACK');
    $headers = $requests[2]['headers'];
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $headers['X-Device-Id'], 'HTTP_X_TIMESTAMP' => $headers['X-Timestamp'], 'HTTP_X_SIGNATURE' => $headers['X-Signature']];
    $this->call('POST', '/api/v1/pollers/android/events', [], [], [], $server, $requests[2]['body'])->assertOk()->assertJsonPath('status', 'VERIFIED');
    Cache::flush();
    $this->call('POST', '/api/v1/pollers/android/events', [], [], [], $server, $requests[2]['body'])->assertUnauthorized();
    app(TenantContext::class)->run($this->merchant->id, function () use ($pair) {
        expect($this->payment->refresh()->status->value)->toBe('SUCCESS');
        expect(AndroidDevice::find($pair->json('deviceId'))->last_observation_at)->not->toBeNull();
        expect(DB::table('android_devices')->where('id', $pair->json('deviceId'))->value('signing_key'))->not->toBe($pair->json('signingKey'));
    });
});

it('rejects missing, tampered, stale and revoked Android credentials', function () {
    $pair = $this->postJson('/api/v1/observers/sms/pair', ['enrollmentToken' => $this->qr['token'], 'deviceName' => 'Phone'])->assertOk()->json();
    $body = json_encode(['body' => 'Rs 200 credited UTR 123456789012', 'sender' => 'BANK-ALERT'], JSON_THROW_ON_ERROR);
    $ts = (string) now()->getTimestampMs();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $pair['deviceId']];
    $this->call('POST', '/api/v1/observers/sms', [], [], [], $headers, $body)->assertUnauthorized();
    $headers['HTTP_X_TIMESTAMP'] = $ts;
    $headers['HTTP_X_SIGNATURE'] = hash_hmac('sha256', $body.':'.$ts, $pair['signingKey']);
    $this->call('POST', '/api/v1/observers/sms', [], [], [], $headers, $body.' ')->assertUnauthorized();
    $headers['HTTP_X_TIMESTAMP'] = (string) (now()->getTimestampMs() - 61000);
    $this->call('POST', '/api/v1/observers/sms', [], [], [], $headers, $body)->assertUnauthorized();
    app(TenantContext::class)->run($this->merchant->id, fn () => AndroidDevice::find($pair['deviceId'])->update(['is_active' => false]));
    $this->withHeader('X-Device-Id', $pair['deviceId'])->postJson('/api/v1/pollers/heartbeat')->assertForbidden();
});

it('scopes pairing to the single-use token and validates QR version and server expiry', function () {
    $other = Merchant::factory()->create();
    $body = ['enrollmentToken' => $this->qr['token'], 'deviceName' => 'Phone', 'merchantId' => $other->id];
    $this->postJson('/api/v1/pollers/android/pair', $body + ['version' => 'v2'])->assertStatus(400);
    $this->postJson('/api/v1/pollers/android/pair', $body + ['expiresAt' => now()->subMinute()->getTimestampMs()])->assertStatus(400);
    $this->postJson('/api/v1/pollers/android/pair', $body)->assertOk()->assertJsonPath('merchantId', $this->merchant->id);
    $this->postJson('/api/v1/pollers/android/pair', $body)->assertUnauthorized();
    $fresh = app(AndroidObserver::class)->issue($this->merchant->id, $this->admin->id)->data;
    $this->travel(11)->minutes();
    $this->postJson('/api/v1/pollers/android/pair', array_replace($body, ['enrollmentToken' => $fresh['token']]))->assertStatus(400);
});

it('requires loopback and the internal token and signed raw text for sidecar observations', function () {
    $this->getJson('/internal/active-window?merchantId='.$this->merchant->id)->assertForbidden();
    $this->withHeader('X-Paykr-Internal-Token', config('paykr.internal_token'))->getJson('/internal/active-window?merchantId='.$this->merchant->id)->assertOk()->assertJsonPath('sessions.0.paymentSessionId', $this->payment->id);
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])->getJson('/internal/active-window?merchantId='.$this->merchant->id)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    $body = json_encode(['merchantId' => $this->merchant->id, 'source' => 'GOOGLE_MESSAGES', 'sender' => 'BANK-ALERT', 'rawText' => 'Rs 200 credited UTR 123456789012'], JSON_THROW_ON_ERROR);
    $ts = (string) now()->getTimestampMs();
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYKR_INTERNAL_TOKEN' => config('paykr.internal_token'), 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.':'.$ts, config('paykr.internal_token'))];
    $this->call('POST', '/internal/observations', [], [], [], $server, $body)->assertOk()->assertJsonPath('status', 'VERIFIED');
    $this->call('POST', '/internal/observations', [], [], [], $server, $body)->assertUnauthorized();
    app(TenantContext::class)->run($this->merchant->id, fn () => expect($this->payment->refresh()->status->value)->toBe('SUCCESS'));
});

it('serves the original APK bytes and restricts pairing-token issuance', function () {
    $this->get('/paykr-sms-gateway.apk')->assertOk()->assertDownload('paykr-sms-gateway.apk');
    $this->get('/api/v1/admin/sms-gateway/download-apk')->assertOk()->assertDownload('paykr-sms-gateway.apk');
    expect(hash_file('sha256', storage_path('app/private/releases/paykr-sms-gateway.apk')))->toBe('aa057e5e3c2ae60a48d66d846c960272bc5b65eef8c3cce16f5953808a97405e');
    $path = '/api/v1/merchant/'.$this->merchant->id.'/observers/pairing-token';
    $this->postJson($path)->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson($path)->assertForbidden();
    $qr = $this->actingAs($this->admin, 'sanctum')->postJson($path)->assertOk()->json('data');
    app(TenantContext::class)->run($this->merchant->id, fn () => expect(ObserverPairingToken::find($qr['sessionId'])->token_hash)->toBe(hash('sha256', $qr['token'])));
});

it('accepts signed Java SMS and non-bank messages but never trusts structured amount overrides', function () {
    $pair = $this->postJson('/api/v1/pollers/android/pair', ['enrollmentToken' => $this->qr['token'], 'deviceName' => 'Android'])->assertOk()->json();
    $send = function (array $payload, string $path = '/api/v1/observers/sms') use ($pair) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) now()->getTimestampMs();

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $pair['deviceId'], 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.':'.$ts, $pair['signingKey'])], $body);
    };
    $send(['body' => 'Hello friend', 'sender' => 'Person', 'amountPaise' => 20000, 'utr' => '123456789012'])->assertOk()->assertJsonPath('status', 'PROCESSED_NON_BANK');
    Cache::flush();
    $this->travel(1)->seconds();
    $send(['body' => 'Hello friend', 'sender' => 'Person', 'amountPaise' => 20000, 'utr' => '123456789012'])->assertOk(); // Different timestamp is a fresh signed request.
    $foreign = app(TenantContext::class)->runUnscoped(fn () => PaymentReceivingAccount::factory()->create(['merchant_id' => Merchant::factory()->create()->id]));
    $send(['body' => 'Rs 200 credited UTR 123456789012', 'sender' => 'BANK-ALERT', 'receivingAccountId' => $foreign->id])->assertStatus(422);
    $send(['deviceId' => $pair['deviceId'], 'sender' => 'BANK-ALERT', 'body' => 'Rs 200 credited UTR 123456789012', 'timestamp' => now()->getTimestampMs()])->assertOk()->assertJsonPath('status', 'VERIFIED');
    $send(['deviceId' => $pair['deviceId']], '/api/v1/observers/heartbeat')->assertOk()->assertJsonPath('status', 'HEARTBEAT_ACK');
});

it('permits static enrollment only in local testing and rejects bad observation timestamps', function () {
    config(['paykr.android.dev_enrollment_secret' => 'dev-enrollment']);
    $body = ['enrollmentToken' => 'dev-enrollment', 'merchantId' => $this->merchant->id, 'deviceName' => 'Dev phone'];
    $this->postJson('/api/v1/pollers/android/pair', $body)->assertOk();
    $this->app->instance('env', 'production');
    $this->postJson('/api/v1/pollers/android/pair', $body)->assertUnauthorized();
    $this->app->instance('env', 'testing');
    $this->postJson('/api/v1/observers/sms', ['observedAt' => ['invalid']])->assertStatus(422);
    $this->postJson('/api/v1/observers/sms', ['observedAt' => 'not-a-date'])->assertStatus(422);
});

it('rejects old amount-only notification shortcuts and preserves fraud handling through signed intake', function () {
    $customer = Customer::factory()->create(['mobile_number' => '9876543210']);
    app(TenantContext::class)->run($this->merchant->id, fn () => $this->payment->update(['customer_id' => $customer->id]));
    $pair = $this->postJson('/api/v1/pollers/android/pair', ['enrollmentToken' => $this->qr['token'], 'deviceName' => 'Phone'])->assertOk()->json();
    $send = function (array $payload) use ($pair) {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) now()->getTimestampMs();

        return $this->call('POST', '/api/v1/observers/sms', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $pair['deviceId'], 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw.':'.$ts, $pair['signingKey'])], $raw);
    };
    $send(['rawNotification' => ['text' => 'Payment of Rs 200 received from customer@paytm on PhonePe', 'package' => 'com.phonepe.app'], 'amountPaise' => 20000, 'bankReferenceNumber' => 'UTR-ANDR-9876'])->assertOk()->assertJsonPath('status', 'REJECTED');
    $result = $send(['body' => 'Rs 200 credited UTR 123456789012', 'sender' => '9876543210'])->assertOk();
    expect($result->json('reason'))->toBe('FRAUDULENT_PAYMENT_INJECTION');
    expect($customer->refresh()->status->value)->toBe('BANNED');
});

it('uses catalog HTTP statuses for missing public APK artifacts', function () {
    $response = app(ApiExceptionRenderer::class)(new ValidationException(ErrorCode::NOT_FOUND), Request::create('/paykr-sms-gateway.apk'));
    expect($response->getStatusCode())->toBe(404);
});

it('refuses internal observations for unknown merchants or unrelated payments', function () {
    expect(fn () => app(InternalObserver::class)->execute(new ObserverRequest('active-window')))->toThrow(ValidationException::class, 'MERCHANT_NOT_FOUND');
    app(TenantContext::class)->run($this->merchant->id, function () {
        expect(fn () => app(ObserverIntake::class)->process(new ObserverRequest('intake', text: 'INR 200 credited UTR 123456789012', accountId: $this->account->id, paymentId: 'missing-payment'), 'SMS'))->toThrow(ValidationException::class, 'PAYMENT_NOT_FOUND');
    });
});
