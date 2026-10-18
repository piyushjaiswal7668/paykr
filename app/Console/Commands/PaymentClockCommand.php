<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Services\PaymentClock;
use Illuminate\Cache\RedisLock;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentClockCommand extends Command
{
    protected $signature = 'paykr:clock {--once : Recover and run one tick}';

    protected $description = 'Recover persisted payment deadlines and run the one-second clock';

    public function handle(PaymentClock $clock): int
    {
        $cache = Cache::store('redis');
        if (! $cache instanceof Repository || ! $cache->getStore() instanceof RedisStore) {
            $this->error('Payment clock requires the Redis cache store.');

            return self::FAILURE;
        }
        $lock = $cache->getStore()->lock('paykr:clock:lock', 5);
        if (! $lock instanceof RedisLock || ! $lock->get()) {
            $this->error('Payment clock is already running.');

            return self::FAILURE;
        }
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
            $running = false;
        });
        $renew = static function () use ($lock): void {
            if (! $lock->refresh()) {
                throw new \RuntimeException('Clock lease lost.');
            }
        };
        $recovery = null;
        try {
            do {
                $started = hrtime(true);
                $renew();
                $counts = $clock->tick($recovery === null, $renew);
                if ($recovery === null) {
                    $recovery = $counts;
                    $this->info('Recovery: '.json_encode($counts, JSON_THROW_ON_ERROR));
                }
                $renew();
                Cache::put('paykr:clock:status', ['status' => 'RUNNING', 'heartbeat' => now()->timestamp, 'recovery' => $recovery, 'lastTick' => $counts], 86400);
                if ($this->option('once')) {
                    break;
                }
                $remaining = 1000000 - (int) ((hrtime(true) - $started) / 1000);
                if ($remaining > 0) {
                    usleep($remaining);
                }
            } while ($running);
        } catch (Throwable $exception) {
            Log::error('Payment clock stopped; supervisor must restart it.', ['exception_type' => $exception::class]);
            $this->error('Payment clock failed; see application logs.');

            return self::FAILURE;
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $state = Cache::get('paykr:clock:status', []);
                if (is_array($state)) {
                    Cache::put('paykr:clock:status', array_replace($state, ['status' => 'STOPPED']), 86400);
                }
            }
            $lock->release();
            $this->untrap();
        }

        return self::SUCCESS;
    }
}
