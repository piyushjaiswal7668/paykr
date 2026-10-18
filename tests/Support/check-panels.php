<?php

declare(strict_types=1);

use App\Domain\Shared\Services\TenantContext;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$database = tempnam(sys_get_temp_dir(), 'paykr-panels-');
$environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8020', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array', 'WHATSAPP_SIDECAR_ENABLED' => 'false', 'MESSAGES_SIDECAR_ENABLED' => 'false'];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = null;
$failure = null;
try {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
        throw new RuntimeException('Database isolation failed');
    }
    if ($socket = @fsockopen('127.0.0.1', 8020, $errno, $error, 0.2)) {
        fclose($socket);
        throw new RuntimeException('Test port 8020 is occupied');
    }
    Artisan::call('migrate', ['--force' => true]);
    $password = bin2hex(random_bytes(16));
    $owner = User::factory()->create(['email' => 'owner@panel-test.example', 'password' => $password]);
    $cashier = User::factory()->create(['email' => 'cashier@panel-test.example', 'password' => $password]);
    $admin = User::factory()->create(['email' => 'admin@panel-test.example', 'password' => $password, 'is_platform_admin' => true]);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $merchant = Merchant::factory()->create(['business_name' => 'Panel Test Store', 'slug' => 'panel-test-store']);
    $merchant->users()->attach($owner, ['role' => 'owner']);
    $merchant->users()->attach($cashier, ['role' => 'cashier']);
    app(TenantContext::class)->run($merchant->id, function () {
        PaymentReceivingAccount::factory()->active()->create(['upi_id' => 'teststore@ybl', 'payee_name' => 'Test Store']);
        PaymentSession::factory()->active()->create(['method' => 'CASH_COUNTER', 'order_id' => 'COUNTER-BROWSER-TEST', 'expires_at' => null]);
    });
    $server = new Process([PHP_BINARY, '-S', '127.0.0.1:8020', $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], $root.'/public', $environment);
    $server->disableOutput();
    $server->start();
    $deadline = microtime(true) + 10;
    do {
        $socket = @fsockopen('127.0.0.1', 8020, $errno, $error, 0.2);
        if ($socket) {
            fclose($socket);
            break;
        }usleep(100000);
    } while (microtime(true) < $deadline);
    if (! $socket || ! $server->isRunning()) {
        throw new RuntimeException('HTTP server failed');
    }
    $browser = new Process([getenv('PAYKR_NODE_PATH') ?: 'node', $root.'/tests/Browser/panels.mjs'], $root, $environment);
    $browser->setInput(json_encode(['password' => $password, 'merchant' => $merchant->slug], JSON_THROW_ON_ERROR));
    $browser->setTimeout(180);
    $browser->mustRun(fn ($type, $output) => print ($output));
    app(TenantContext::class)->run($merchant->id, function () use ($owner): void {
        $audit = AuditLog::where('action', 'COUNTER_MANUAL_PAYMENT_CONFIRMED')->sole();
        if ($audit->actor_id !== $owner->id || $audit->ip_address !== '127.0.0.1') {
            throw new RuntimeException('Browser mutation audit attribution failed');
        }
    });
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $server?->stop(3);
    @unlink($database);
    @unlink($database.'-wal');
    @unlink($database.'-shm');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
