<?php

use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantBranding;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentReceivingAccount;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

$merchantDemo = in_array('--merchant-demo', $argv, true);
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$database = tempnam(sys_get_temp_dir(), 'paykr-checkout-');
$environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8022', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'REVERB_APP_KEY' => '', 'REVERB_HOST' => '', 'MAIL_MAILER' => 'array', 'WHATSAPP_SIDECAR_ENABLED' => 'false', 'MESSAGES_SIDECAR_ENABLED' => 'false'];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.connections.redis.driver' => 'sync']);
$server = null;
$failure = null;
try {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
        throw new RuntimeException('Test isolation failed');
    }
    foreach ([8022, 8023] as $port) {
        if ($s = @fsockopen('127.0.0.1', $port, $errno, $error, .2)) {
            fclose($s);
            throw new RuntimeException('Test port occupied');
        }
    }
    Artisan::call('migrate', ['--force' => true]);
    $merchant = Merchant::factory()->create(['business_name' => 'Paykr Demo Store', 'allowed_origins' => ['http://127.0.0.1:8023'], 'webhook_url' => 'http://127.0.0.1:8023/webhook', 'webhook_secret' => 'isolated-checkout-secret']);
    $owner = User::factory()->create();
    $merchant->users()->attach($owner, ['role' => 'owner']);
    $key = ($merchantDemo ? 'sk_live_' : 'pk_live_').bin2hex(random_bytes(32));
    $account = app(TenantContext::class)->run($merchant->id, function () use ($key, $merchantDemo) {
        MerchantApiKey::factory()->create(['key_hash' => hash('sha256', $key), 'type' => $merchantDemo ? 'secret' : 'publishable', 'mode' => 'live']);
        MerchantPaymentSettings::factory()->create(['selling_mode' => 'ONLINE', 'cod_enabled' => true]);
        MerchantBranding::factory()->create(['app_name' => 'Paykr', 'primary_color' => '#2563eb', 'support_emails' => ['help@example.test']]);

        return PaymentReceivingAccount::factory()->active()->create(['upi_id' => 'checkouttest@ybl', 'payee_name' => 'Test Store']);
    });
    $pair = app(AndroidObserver::class)->issue($merchant->id, $owner->id)->data;
    $server = new Process([PHP_BINARY, '-d', 'error_log=/tmp/paykr-checkout-server.log', '-d', 'display_errors=0', '-S', '127.0.0.1:8022', $root.'/tests/Support/checkout-router.php'], $root.'/public', $environment);
    $server->disableOutput();
    $server->start();
    $deadline = microtime(true) + 10;
    do {
        $s = @fsockopen('127.0.0.1', 8022, $errno, $error, .2);
        if ($s) {
            fclose($s);
            break;
        }usleep(100000);
    } while (microtime(true) < $deadline);
    $browser = new Process([getenv('PAYKR_NODE_PATH') ?: 'node', $root.($merchantDemo ? '/tests/Integration/merchant-demo.mjs' : '/tests/Browser/checkout.mjs')], $root, $environment);
    $browser->setInput(json_encode(['key' => $key, 'merchantId' => $merchant->id, 'ownerId' => $owner->id, 'accountId' => $account->id, 'enrollmentToken' => $pair['enrollmentToken'], 'php' => PHP_BINARY]));
    $browser->setTimeout(240);
    $browser->mustRun(fn ($type, $out) => print ($out));
} catch (Throwable $e) {
    $failure = $e;
} finally {
    $server?->stop(3);
    @unlink($database);
    @unlink($database.'-wal');
    @unlink($database.'-shm');
}
if ($failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
