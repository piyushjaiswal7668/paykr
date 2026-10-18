<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id', '');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) {
            $id = (string) Str::ulid();
        }
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);
        try {
            $response = $next($request);
            $response->headers->set('X-Request-Id', $id);

            return $response;
        } finally {
            Log::withoutContext(['request_id']);
        }
    }
}
