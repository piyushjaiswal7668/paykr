<?php

/** Real HTTP + Reverb + Redis worker + browser, using disposable data only. */
declare(strict_types=1);

use App\Domain\Shared\Services\TenantContext;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\PaymentSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$database = tempnam(sys_get_temp_dir(), 'paykr-realtime-');
$prefix = 'paykr-realtime-'.bin2hex(random_bytes(8)).':';
$environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8019',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'REDIS_PREFIX' => $prefix, 'CACHE_PREFIX' => $prefix, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false',
    'QUEUE_CONNECTION' => 'redis', 'BROADCAST_CONNECTION' => 'reverb', 'MAIL_MAILER' => 'array',
    'REVERB_APP_ID' => 'isolated-test', 'REVERB_APP_KEY' => 'isolated-test-key', 'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
    'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => '8089', 'REVERB_SCHEME' => 'http', 'REVERB_SERVER_PORT' => '8089', 'REVERB_ALLOWED_ORIGINS' => '127.0.0.1'];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$processes = [];
$failure = null;
try {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database || config('database.redis.options.prefix') !== $prefix) {
        throw new RuntimeException('Isolation failed');
    }
    Artisan::call('migrate', ['--force' => true]);
    $merchant = Merchant::factory()->create();
    $token = bin2hex(random_bytes(32));
    $device = Device::factory()->create(['device_token_hash' => hash('sha256', $token)]);
    $payments = app(TenantContext::class)->run($merchant->id, function (): array {
        $ids = [];
        foreach (['live', 'blocked', 'reconnect'] as $mode) {
            $ids[$mode] = PaymentSession::factory()->active()->create(['device_id' => null])->id;
        }

        return $ids;
    });
    foreach ([['serve', '--host=127.0.0.1', '--port=8019', '--no-reload'], ['reverb:start', '--host=127.0.0.1', '--port=8089'], ['queue:work', 'redis', '--queue=payments', '--sleep=1', '--tries=1', '--timeout=15']] as $command) {
        $process = $command[0] === 'serve'
            ? new Process([PHP_BINARY, '-S', '127.0.0.1:8019', $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], $root.'/public', $environment)
            : new Process([PHP_BINARY, $root.'/artisan', ...$command], $root, $environment);
        $process->disableOutput();
        $process->start();
        $processes[] = $process;
    }
    foreach ([8019, 8089] as $port) {
        $deadline = microtime(true) + 10;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        if (! $socket) {
            throw new RuntimeException('Test server did not start');
        }
    }
    foreach ($processes as $process) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Test process exited: '.$process->getErrorOutput());
        }
    }
    $browser = new Process([getenv('PAYKR_NODE_PATH') ?: 'node', $root.'/tests/Browser/realtime.mjs'], $root, $environment);
    $browser->setInput(json_encode(['url' => 'http://127.0.0.1:8019', 'payments' => $payments, 'deviceId' => $device->id, 'deviceToken' => $token, 'php' => PHP_BINARY], JSON_THROW_ON_ERROR));
    $browser->setTimeout(90);
    $browser->mustRun(fn ($type, $output) => print ($output));
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach (array_reverse($processes) as $process) {
        $process->stop(3);
    }
    foreach (['default', 'cache'] as $name) {
        $connection = Redis::connection($name);
        foreach ($connection->keys('*') as $key) {
            if (str_starts_with($key, $prefix)) {
                $connection->del(substr($key, strlen($prefix)));
            }
        }
    }
    @unlink($database);
    @unlink($database.'-wal');
    @unlink($database.'-shm');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
