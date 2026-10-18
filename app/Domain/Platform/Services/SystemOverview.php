<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Observers\Services\WhatsAppSidecar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SystemOverview
{
    /** @return array<string, mixed> */
    public function read(): array
    {
        return Cache::remember('panel-system-health', 15, function (): array {
            $socket = @fsockopen((string) config('reverb.servers.reverb.host'), (int) config('reverb.servers.reverb.port'), $code, $message, 0.3);
            $reverb = $socket !== false;
            if ($socket !== false) {
                fclose($socket);
            }

            return ['Reverb' => $reverb ? 'Listening' : 'Unavailable', 'WhatsApp' => app(WhatsAppSidecar::class)->status()['status'] ?? 'Unavailable', 'Google Messages' => config('paykr.sidecars.messages_enabled') ? 'Enabled' : 'Disabled', 'Failed jobs' => DB::table('failed_jobs')->count()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function failedJobs(): array
    {
        return array_values(DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->get(['uuid', 'queue', 'failed_at'])->map(fn (object $row): array => ['uuid' => $row->uuid, 'queue' => $row->queue, 'failed_at' => $row->failed_at])->all());
    }
}
