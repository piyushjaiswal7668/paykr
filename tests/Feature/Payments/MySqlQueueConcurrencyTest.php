<?php

declare(strict_types=1);

use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\AndroidDevice;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires independent MySQL connections and real row locks.');
    }
    expect(str_ends_with(DB::connection()->getDatabaseName(), '_test'))->toBeTrue();
    $this->artisan('migrate:fresh');
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function runQueueContenders(string $merchantId, string $accountId, array $paymentIds, string $operation): array
{
    $db = config('database.connections.mysql');
    $barrier = sys_get_temp_dir().'/paykr-queue-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($paymentIds as $index => $paymentId) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/queue-concurrency-worker.php'), $merchantId, $accountId, $paymentId, $barrier, (string) $index, $operation], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
                'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
                'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            ]);
            $process->setTimeout(90);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 60;
        while (count(glob($barrier.'.ready.*')) !== count($paymentIds)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Queue worker failed before barrier: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach the start barrier.');
            }
            usleep(10000);
        }
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode(), $process->getErrorOutput().$process->getOutput())->toBe(0);
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        foreach (glob($barrier.'*') as $file) {
            unlink($file);
        }
    }
}

it('serializes 50 concurrent customers into one active slot and 49 unique queue positions on MySQL', function () {
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook', 'webhook_secret' => 'test-secret']);
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $ids = Customer::factory()->count(50)->create()->map(fn ($customer) => PaymentSession::factory()->forAccount($account)->create(['customer_id' => $customer->id])->id)->all();
        $results = runQueueContenders($merchant->id, $account->id, $ids, 'enqueue');
        expect(collect($results)->where('status', 'ACTIVE'))->toHaveCount(1);
        expect(collect($results)->where('status', 'QUEUED'))->toHaveCount(49);
        expect(PaymentSession::where('status', PaymentStatus::ACTIVE)->count())->toBe(1);
        expect(PaymentSession::where('status', PaymentStatus::QUEUED)->orderBy('queue_position')->pluck('queue_position')->all())->toBe(range(1, 49));
        $active = PaymentSession::where('status', PaymentStatus::ACTIVE)->sole();
        expect($account->refresh()->current_active_session_id)->toBe($active->id);
        expect(AuditLog::where('action', 'PAYMENT_SLOT_ACTIVATED')->count())->toBe(1);
        expect(AuditLog::where('action', 'PAYMENT_ENQUEUED')->count())->toBe(49);
        $done = runQueueContenders($merchant->id, $account->id, array_fill(0, 10, $active->id), 'done');
        expect(collect($done)->where('status', 'PROCESSING'))->toHaveCount(10);
        expect(array_unique(array_column($done, 'deadline')))->toHaveCount(1);
        expect(AuditLog::where('action', 'PAYMENT_PROCESSING')->count())->toBe(1);
        // Ten simultaneous terminal requests must produce one committed outcome and event.
        $results = runQueueContenders($merchant->id, $account->id, array_fill(0, 10, $active->id), 'cancel');
        expect(array_sum(array_column($results, 'events')))->toBe(1);
        expect(collect($results)->where('status', 'CANCELLED'))->toHaveCount(1);
        expect(collect($results)->where('status', 'ALREADY_CLOSED'))->toHaveCount(9);
        expect(AuditLog::where('action', 'PAYMENT_CANCELLED')->count())->toBe(1);
        expect(WebhookDelivery::where('payment_id', $active->id)->count())->toBe(1);
        $delivery = WebhookDelivery::where('payment_id', $active->id)->sole();
        $deliveries = runQueueContenders($merchant->id, $account->id, array_fill(0, 10, $delivery->id), 'deliver');
        expect(array_sum(array_column($deliveries, 'requests')))->toBe(1);
        expect($delivery->refresh()->attempt_count)->toBe(1)->and($delivery->is_successful)->toBeTrue();
        expect(PaymentSession::where('status', PaymentStatus::ACTIVE)->count())->toBe(1);
        expect(PaymentSession::where('status', PaymentStatus::QUEUED)->orderBy('queue_position')->pluck('queue_position')->all())->toBe(range(1, 48));
    });
});

it('serializes simultaneous observer channels into one success and one webhook on MySQL', function () {
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook', 'webhook_secret' => 'test-secret']);
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $payment = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000]);
        app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
        $results = runQueueContenders($merchant->id, $account->id, array_fill(0, 10, $payment->id), 'observe');
        expect(collect($results)->where('status', 'VERIFIED'))->toHaveCount(2);
        expect(collect($results)->where('reason', 'DUPLICATE_OBSERVATION'))->toHaveCount(8);
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS)->and($payment->verification_level)->toBe(2);
        expect(PaymentObservation::count())->toBe(2);
        expect(WebhookDelivery::count())->toBe(1);
        expect(AuditLog::where('action', 'PAYMENT_SUCCESS')->count())->toBe(1);
    });
});

it('creates one payment for concurrent identical API requests', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($merchant) {
        $account = PaymentReceivingAccount::factory()->active()->create();
        $results = runQueueContenders($merchant->id, $account->id, array_fill(0, 10, 'unused'), 'create');
        expect(array_unique(array_column($results, 'paymentId')))->toHaveCount(1);
        expect(array_sum(array_column($results, 'created')))->toBe(1);
        expect(PaymentSession::count())->toBe(1);
    });
});

it('consumes pairing and signed observation receipts once across concurrent workers', function () {
    $merchant = Merchant::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $qr = app(AndroidObserver::class)->issue($merchant->id, $admin->id)->data;
    $paired = runQueueContenders($merchant->id, 'unused', array_fill(0, 10, $qr['token']), 'pair');
    expect(collect($paired)->where('status', 'PAIRED'))->toHaveCount(1);
    expect(collect($paired)->where('status', 'PAIRING_TOKEN_INVALID'))->toHaveCount(9);
    $device = app(TenantContext::class)->run($merchant->id, fn () => AndroidDevice::sole());
    $results = runQueueContenders($merchant->id, (string) now()->getTimestampMs(), array_fill(0, 10, $device->id), 'signed-replay');
    expect(collect($results)->where('status', 'PROCESSED_NON_BANK'))->toHaveCount(1);
    expect(collect($results)->where('status', 'SIGNATURE_INVALID'))->toHaveCount(9);
});
