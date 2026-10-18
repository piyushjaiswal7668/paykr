<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Persisted recovery is safe to repeat; normal deadline jobs remain the first path.
Schedule::command('paykr:webhooks:retry-pending')->everyMinute()->withoutOverlapping();
Schedule::command('paykr:verification:recover')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => DB::table('gmail_processed_messages')->where('processed_at', '<', now()->subDays(30))->delete())->daily()->name('prune-gmail-receipts')->withoutOverlapping();
Schedule::call(function (): void {
    DB::table('observer_request_receipts')->where('expires_at', '<=', now())->delete();
    DB::table('gmail_oauth_states')->where('expires_at', '<=', now())->delete();
})->hourly()->name('prune-observer-security-receipts')->withoutOverlapping();
