<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Merchants\Services\ApiKeyAuthenticator;
use App\Domain\Shared\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MerchantApiAuthentication
{
    public function __construct(private ApiKeyAuthenticator $keys, private TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $this->keys->authenticate($request->header('X-Api-Key') ?? $request->header('X-Merchant-Key') ?? $request->bearerToken(), $request->header('Origin'), $request->isMethod('GET'), $request->header('X-Merchant-Id'));
        $request->attributes->set('api_identity', $identity);

        return $this->tenants->run($identity->merchantId, fn () => $next($request));
    }
}
