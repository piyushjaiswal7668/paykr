<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

final class SetMerchantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Merchant, 403);

        // Livewire replays persistent middleware before invoking the component,
        // so its inner pipeline does not wrap the actual action or render.
        if (Livewire::isLivewireRequest()) {
            app(TenantContext::class)->establish($tenant->id);

            return $next($request);
        }

        return app(TenantContext::class)->run($tenant->id, fn () => $next($request));
    }
}
