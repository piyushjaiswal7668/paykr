<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InternalObserverAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('paykr.internal_token');
        if (! in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true) || ! is_string($token) || $token === '' || ! hash_equals($token, $request->header('X-Paykr-Internal-Token', ''))) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return $next($request);
    }
}
