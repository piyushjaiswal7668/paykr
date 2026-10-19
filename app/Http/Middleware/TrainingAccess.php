<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrainingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        $isAdmin = $admin !== null && $admin->is_platform_admin && $admin->getAppAuthenticationSecret() !== null;
        $merchant = $request->user('web');
        abort_unless($isAdmin || ($request->route('audience') !== 'admin' && $merchant !== null && $merchant->merchants()->where('status', 'ACTIVE')->exists()), 403);

        return $next($request);
    }
}
