<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Management\Services\PanelAccess;
use App\Domain\Shared\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EndImpersonationController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $actor = app(PanelAccess::class)->admin((string) auth('admin')->id(), $request->ip());
        app(TenantContext::class)->runUnscoped(fn () => app(PanelAccess::class)->audit($actor, 'ADMIN_IMPERSONATION_ENDED', 'USER', auth('web')->id()));
        auth('web')->logout();
        $request->session()->forget(['paykr_impersonator', 'paykr_impersonation_expires']);
        $request->session()->regenerate();

        return redirect('/admin/merchants');
    }
}
