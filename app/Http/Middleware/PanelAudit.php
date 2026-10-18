<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Management\Services\PanelAuditContext;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\ValueObjects\Actor;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

final class PanelAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        abort_unless($user !== null, 403);
        $context = app(PanelAuditContext::class);
        $previous = [$context->actor, $context->impersonatedUserId];
        $impersonator = $request->session()->get('paykr_impersonator');
        if (Filament::getCurrentPanel()?->getId() === 'merchant' && $impersonator !== null) {
            if (auth('admin')->id() !== $impersonator || ! auth('admin')->user()?->is_platform_admin || $request->session()->get('paykr_impersonation_expires', 0) < now()->timestamp) {
                auth('web')->logout();
                $request->session()->forget(['paykr_impersonator', 'paykr_impersonation_expires']);
                abort(403, 'Support session expired.');
            }
            $context->actor = new Actor(ActorType::PLATFORM_ADMIN, $impersonator, ip: $request->ip());
            $context->impersonatedUserId = (string) $user->getAuthIdentifier();
        } else {
            $context->actor = new Actor(Filament::getCurrentPanel()?->getId() === 'admin' ? ActorType::PLATFORM_ADMIN : ActorType::MERCHANT_USER, (string) $user->getAuthIdentifier(), ip: $request->ip());
        }
        // Keep scoped audit identity through the subsequent Livewire action.
        if (Livewire::isLivewireRequest()) {
            return $next($request);
        }
        try {
            return $next($request);
        } finally {
            [$context->actor, $context->impersonatedUserId] = $previous;
        }
    }
}
