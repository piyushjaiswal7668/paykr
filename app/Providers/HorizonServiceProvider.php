<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        Horizon::auth(function (): bool {
            $user = Auth::guard('admin')->user();

            return $user instanceof User && $user->is_platform_admin && filled($user->getAppAuthenticationSecret());
        });
    }
}
