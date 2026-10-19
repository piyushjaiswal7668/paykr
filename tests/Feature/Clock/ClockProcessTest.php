<?php

declare(strict_types=1);

use App\Domain\Platform\Services\ClockStatus;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('kills and restarts the clock mid-payment with singleton ownership and sub-second deadline recovery while running', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires real MySQL, Redis and independent clock processes.');
    }
    expect(str_ends_with(DB::connection()->getDatabaseName(), '_test'))->toBeTrue();
    $this->artisan('migrate:fresh');
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook']);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'redis',
        'CACHE_PREFIX' => Cache::store('redis')->getStore()->getPrefix(), 'BROADCAST_CONNECTION' => 'null'];
    $start = fn (bool $once = false) => new Process([PHP_BINARY, base_path('tests/Support/clock-worker.php'), ...($once ? ['--once'] : [])], base_path(), $environment);
    $first = $start();
    $second = null;
    try {
        app(TenantContext::class)->run($merchant->id, function () use ($first, $start, &$second) {
            $account = PaymentReceivingAccount::factory()->active()->create();
            $payment = PaymentSession::factory()->forAccount($account)->create(['status' => 'ACTIVE', 'expires_at' => now()->addMinutes(1)]);
            $account->update(['is_slot_busy' => true, 'current_active_session_id' => $payment->id]);
            $deadline = now()->toImmutable()->addMilliseconds(2400);
            $active = PaymentSession::factory()->create(['method' => 'COD', 'status' => 'ACTIVE', 'expires_at' => $deadline]);
            $processing = PaymentSession::factory()->create(['method' => 'COD', 'status' => 'PROCESSING', 'processing_expires_at' => $deadline]);
            $first->start();
            $end = microtime(true) + 8;
            while (! str_contains($first->getOutput(), 'Recovery:') && $first->isRunning() && microtime(true) < $end) {
                usleep(20000);
            }
            expect($first->isRunning(), $first->getOutput().$first->getErrorOutput())->toBeTrue();
            $duplicate = $start(true);
            $duplicate->run();
            expect($duplicate->getExitCode())->toBe(1);
            expect($duplicate->getOutput())->toContain('already running');
            while ((! $active->refresh()->status->isTerminal() || ! $processing->refresh()->status->isTerminal()) && microtime(true) < $end) {
                usleep(20000);
            }
            expect($active->status->value)->toBe('EXPIRED')->and($processing->status->value)->toBe('FAILED');
            foreach ([$active, $processing] as $closed) {
                expect($deadline->diffInMilliseconds($closed->completed_at, false))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1000);
            }
            expect($payment->refresh()->status->value)->toBe('ACTIVE');
            $first->signal(SIGKILL);
            $first->wait();
            $payment->update(['expires_at' => now()->subSecond()]);
            // A crashed owner's lease expires; never force-release another process's lock.
            $lease = Cache::store('redis')->lock('paykr:clock:lock', 5);
            $end = microtime(true) + 7;
            while (! $lease->get()) {
                if (microtime(true) >= $end) {
                    throw new RuntimeException('Crashed clock lease did not expire.');
                }
                usleep(50000);
            }
            $lease->release();
            $second = $start(true);
            $second->run();
            expect($second->getExitCode(), $second->getOutput().$second->getErrorOutput())->toBe(0);
            expect($payment->refresh()->status->value)->toBe('EXPIRED');
            expect(WebhookDelivery::where('payment_id', $payment->id)->count())->toBe(1);
            expect(app(ClockStatus::class)->read()['recovery']['expiredCount'])->toBe(1);
        });
    } finally {
        $first->stop();
        $second?->stop();
        RefreshDatabaseState::$migrated = false;
    }
});
