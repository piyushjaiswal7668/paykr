<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RedisLock;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Signals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Signals::resolveAvailabilityUsing(fn () => true);
    Cache::swap(Mockery::mock(CacheManager::class, [app()])->makePartial());
});

afterEach(fn () => Signals::resolveAvailabilityUsing(fn () => false));

it('rejects a clock cache without Redis leases', function () {
    Cache::shouldReceive('store')->with('redis')->andReturn(new Repository(new ArrayStore));
    $this->artisan('paykr:clock --once')->expectsOutput('Payment clock requires the Redis cache store.')->assertFailed();
});

it('rejects a second clock while another owner holds the lease', function () {
    $lock = Mockery::mock(RedisLock::class);
    $lock->shouldReceive('get')->once()->andReturnFalse();
    $store = Mockery::mock(RedisStore::class);
    $store->shouldReceive('lock')->with('paykr:clock:lock', 5)->andReturn($lock);
    Cache::shouldReceive('store')->with('redis')->andReturn(new Repository($store));
    $this->artisan('paykr:clock --once')->expectsOutput('Payment clock is already running.')->assertFailed();
});

it('runs and cleans up a clock with lease loss and graceful stop handling', function (string $mode) {
    $lock = Mockery::mock(RedisLock::class);
    $lock->shouldReceive('get')->once()->andReturnTrue();
    $calls = 0;
    $lock->shouldReceive('refresh')->andReturnUsing(function () use ($mode, &$calls) {
        $calls++;
        if ($mode === 'lost') {
            return false;
        }
        if ($mode === 'signal' && $calls === 2) {
            $handler = pcntl_signal_get_handler(SIGTERM);
            expect(is_callable($handler))->toBeTrue();
            $handler(SIGTERM);
        }

        return true;
    });
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturn($mode !== 'lost');
    $lock->shouldReceive('release')->once()->andReturnTrue();
    $store = Mockery::mock(RedisStore::class);
    $store->shouldReceive('lock')->with('paykr:clock:lock', 5)->andReturn($lock);
    Cache::shouldReceive('store')->with('redis')->andReturn(new Repository($store));
    $this->artisan('paykr:clock', ['--once' => $mode !== 'signal'])->assertExitCode($mode === 'lost' ? 1 : 0);
    if ($mode !== 'lost') {
        expect(Cache::get('paykr:clock:status')['status'])->toBe('STOPPED');
        expect(Cache::get('paykr:clock:status')['recovery']['expiredCount'])->toBe(0);
    }
})->with(['once', 'lost', 'signal']);
