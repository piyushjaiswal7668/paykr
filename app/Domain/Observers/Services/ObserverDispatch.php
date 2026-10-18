<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Jobs\PollGmail;
use App\Domain\Observers\Jobs\PollGmailNow;
use App\Domain\Observers\Jobs\SweepMessages;
use App\Domain\Verification\Jobs\ReattributePendingObservations;
use App\Models\MerchantGmailConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ObserverDispatch
{
    public function dispatch(string $merchantId, ?string $accountId, bool $processing = false): void
    {
        // Two-second burst interval is the first whole clock tick after the 1.5-second minimum.
        $key = 'paykr:observers:dispatch:'.$merchantId.':'.($accountId === null ? 'idle' : ($processing ? 'burst' : 'active'));
        if (! Cache::add($key, true, $accountId === null ? 60 : ($processing ? 2 : 5))) {
            return;
        }
        try {
            if (MerchantGmailConnection::where('merchant_id', $merchantId)->whereNotNull('refresh_token')->exists()) {
                Bus::dispatch($processing ? new PollGmailNow($merchantId) : new PollGmail($merchantId));
            }
            if ($accountId !== null) {
                Bus::dispatch(new ReattributePendingObservations($merchantId, $accountId));
                if (config('paykr.sidecars.messages_enabled')) {
                    Bus::dispatch(new SweepMessages($merchantId));
                }
            }
        } catch (Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }
    }
}
