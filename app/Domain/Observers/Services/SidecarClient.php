<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SidecarClient
{
    /**
     * @param  'whatsapp'|'messages'  $service
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function request(string $service, string $path, array $data = [], bool $get = false): ?array
    {
        if (! config('paykr.sidecars.'.$service.'_enabled')) {
            return null;
        }
        $key = 'paykr:sidecar:'.$service.':open';
        try {
            if ((int) Cache::get($key, 0) > now()->getTimestamp()) {
                return null;
            }
            $url = (string) config('paykr.sidecars.'.$service.'_url');
            $token = (string) config('paykr.internal_token');
            if (! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', '[::1]'], true) || parse_url($url, PHP_URL_SCHEME) !== 'http' || $token === '') {
                return null;
            }
            $request = Http::withHeaders(['X-Paykr-Internal-Token' => $token])->acceptJson()->connectTimeout(1)->timeout(2)->withoutRedirecting();
            $response = $get ? $request->get(rtrim($url, '/').$path) : $request->send('POST', rtrim($url, '/').$path, ['json' => (object) $data]);
            $result = $response->json();
            if ($response->successful() && is_array($result)) {
                return $result;
            }
            Cache::put($key, now()->addSeconds(15)->getTimestamp(), 15);
        } catch (Throwable) {
            // A failed sidecar or cache must not take checkout down.
            try {
                Cache::put($key, now()->addSeconds(15)->getTimestamp(), 15);
            } catch (Throwable) {
            }
        }

        return null;
    }
}
