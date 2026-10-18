<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class ClockStatus
{
    /** @return array<string, mixed> */
    public function read(): array
    {
        try {
            $state = Cache::get('paykr:clock:status', []);
            if (! is_array($state)) {
                $state = [];
            }
            $state['healthy'] = isset($state['heartbeat']) && is_int($state['heartbeat']) && now()->getTimestamp() - $state['heartbeat'] >= 0 && now()->getTimestamp() - $state['heartbeat'] <= 3 && ($state['status'] ?? null) === 'RUNNING';

            return $state + ['status' => 'STOPPED', 'heartbeat' => null, 'recovery' => null];
        } catch (Throwable) {
            return ['status' => 'UNAVAILABLE', 'healthy' => false, 'heartbeat' => null, 'recovery' => null];
        }
    }
}
