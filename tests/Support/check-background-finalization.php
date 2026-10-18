<?php

/** Standalone runtime smoke check: isolated SQLite database + unique Redis namespace. */
declare(strict_types=1);

use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$database = tempnam(sys_get_temp_dir(), 'paykr-finalization-');
$prefix = 'paykr-finalization-'.bin2hex(random_bytes(8)).':';
$environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'REDIS_PREFIX' => $prefix, 'CACHE_PREFIX' => $prefix, 'CACHE_STORE' => 'redis',
    'QUEUE_CONNECTION' => 'redis', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array'];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$process = null;
function verifyRuntime(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
try {
    verifyRuntime(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $database, 'Database isolation failed');
    verifyRuntime(config('database.redis.options.prefix') === $prefix, 'Redis isolation failed');
    Artisan::call('migrate', ['--force' => true]);
    config(['paykr.payments.grace_period_ms' => 1800]);
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook']);
    app(TenantContext::class)->run($merchant->id, function () use ($root, $environment, &$process): void {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        foreach (['redis-worker', 'clock-recovery', 'scheduler-recovery'] as $index => $mode) {
            if ($mode !== 'redis-worker') {
                // Simulate initial dispatch loss; only the persisted deadline remains.
                Bus::fake([FinalizeLevel1Payment::class]);
            }
            $payment = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 100]);
            app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
            $result = app(VerificationPipeline::class)->process(new VerificationData('SMS', 'Rs 1 credited UTR: 12345678901'.$index, $account->id, sender: 'VM-HDFCBK', receivedAt: now()->toImmutable()));
            verifyRuntime($result->isVerified(), 'Synthetic observation did not match');
            verifyRuntime($payment->refresh()->status->value === 'ACTIVE', 'Payment finalized before grace');
            $grace = $payment->verification_grace_expires_at;
            if ($mode === 'scheduler-recovery') {
                usleep(2000000);
                $scheduler = new Process([PHP_BINARY, $root.'/artisan', 'schedule:run'], $root, $environment);
                $scheduler->mustRun();
                verifyRuntime(str_contains($scheduler->getOutput(), 'paykr:verification:recover'), 'Scheduler did not run recovery');
            }
            $command = $mode === 'clock-recovery'
                ? [PHP_BINARY, $root.'/artisan', 'paykr:clock']
                : [PHP_BINARY, $root.'/artisan', 'queue:work', 'redis', '--queue=payments', '--sleep=1', '--tries=1', '--timeout=15'];
            $process = new Process($command, $root, $environment);
            $process->start();
            $deadline = microtime(true) + 15;
            while ($payment->refresh()->status->value !== 'SUCCESS' && microtime(true) < $deadline && $process->isRunning()) {
                usleep(100000);
            }
            verifyRuntime($payment->status->value === 'SUCCESS', $mode.' failed: '.$process->getOutput().$process->getErrorOutput());
            verifyRuntime($payment->completed_at->greaterThanOrEqualTo($grace), 'Finalized before grace deadline');
            verifyRuntime(! $account->refresh()->is_slot_busy, 'Receiving slot not released');
            echo $mode.': PASS — automatic SUCCESS after grace, receiving slot released'.PHP_EOL;
            $process->stop(3);
            $process = null;
        }
    });
} finally {
    $process?->stop(3);
    // Delete only this run's unique Redis keys; never flush a shared database.
    foreach (['default', 'cache'] as $name) {
        $connection = Redis::connection($name);
        foreach ($connection->keys('*') as $key) {
            if (str_starts_with($key, $prefix)) {
                $connection->del(substr($key, strlen($prefix)));
            }
        }
    }
    DB::disconnect();
    foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
