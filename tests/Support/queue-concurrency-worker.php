<?php

declare(strict_types=1);

use App\Domain\Merchants\Data\ApiIdentity;
use App\Domain\Observers\Data\ObserverRequest;
use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Payments\Data\PaymentApiData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentCancelled;
use App\Domain\Payments\Services\PaymentApiService;
use App\Domain\Payments\Services\PaymentTimingService;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Webhooks\Services\WebhookService;
use App\Models\AndroidDevice;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    throw new RuntimeException('Concurrency worker requires a MySQL *_test database.');
}
[$script, $merchantId, $accountId, $paymentId, $barrier, $workerId, $operation] = $argv;
// Open each independent database connection before signalling readiness.
DB::connection()->getPdo();
touch($barrier.'.ready.'.$workerId);
$deadline = microtime(true) + 60;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency start barrier timed out.');
    }
    usleep(10000);
}
Bus::fake([DeliverWebhook::class, FinalizeLevel1Payment::class]);
if (in_array($operation, ['pair', 'signed-replay'], true)) {
    try {
        $data = $operation === 'pair'
            ? new ObserverRequest('pair', enrollmentToken: $paymentId, deviceName: 'Concurrent Phone', merchantId: $merchantId)
            : app(TenantContext::class)->run($merchantId, function () use ($paymentId, $accountId) {
                $device = AndroidDevice::findOrFail($paymentId);
                $raw = '{"body":"Hello","sender":"BANK"}';

                return new ObserverRequest('observe', deviceId: $device->id, rawBody: $raw, timestamp: $accountId, signature: hash_hmac('sha256', $raw.':'.$accountId, $device->signing_key), text: 'Hello', sender: 'BANK');
            });
        $result = app(AndroidObserver::class)->execute($data);
        echo json_encode(['status' => $result->data['status'] ?? 'PAIRED'], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['status' => $error->errorCode->value], JSON_THROW_ON_ERROR);
    }
    exit(0);
}
if ($operation === 'create') {
    $result = app(TenantContext::class)->run($merchantId, fn () => app(PaymentApiService::class)->execute(new PaymentApiData(new ApiIdentity($merchantId, false, true), 'create', orderId: 'CONCURRENT-ORDER', amount: '12.34', idempotencyKey: 'concurrent-key')));
    echo json_encode(['paymentId' => $result->data['paymentId'], 'created' => $result->created], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($operation === 'done') {
    $payment = app(TenantContext::class)->run($merchantId, fn () => app(PaymentTimingService::class)->paymentDone($paymentId, new Actor(ActorType::SYSTEM)));
    echo json_encode(['status' => $payment->status->value, 'deadline' => $payment->processing_expires_at?->format('Y-m-d H:i:s.v')], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($operation === 'observe') {
    config(['paykr.payments.grace_period_ms' => 0]);
    $result = app(TenantContext::class)->run($merchantId, fn () => app(VerificationPipeline::class)->process(
        new VerificationData((int) $workerId % 2 === 0 ? 'SMS' : 'GMAIL', 'Rs 200 credited UTR: 123456789012', $accountId, paymentSessionId: $paymentId)
    ));
    echo json_encode(['status' => $result->data['status'], 'reason' => $result->data['reason']], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($operation === 'deliver') {
    Http::preventStrayRequests();
    Http::fake(function () {
        usleep(100000);

        return Http::response('received', 200);
    });
    app(TenantContext::class)->run($merchantId, fn () => app(WebhookService::class)->deliver($paymentId));
    echo json_encode(['status' => 'DELIVERED', 'requests' => Http::recorded()->count()], JSON_THROW_ON_ERROR);
    exit(0);
}
$events = 0;
Event::listen(PaymentCancelled::class, function () use (&$events) {
    $events++;
});
try {
    $payment = app(TenantContext::class)->run($merchantId, fn () => $operation === 'enqueue'
        ? app(SlotQueueService::class)->enqueueOrActivate($paymentId, $accountId, new Actor(ActorType::SYSTEM))
        : app(PaymentTransitionService::class)->transition($paymentId, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM)));
    echo json_encode(['status' => $payment->status->value, 'events' => $events], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    if ($operation !== 'cancel' || $exception->errorCode !== ErrorCode::PAYMENT_CLOSED) {
        throw $exception;
    }
    echo json_encode(['status' => 'ALREADY_CLOSED', 'events' => $events], JSON_THROW_ON_ERROR);
}
