<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Management\Services\PanelAccess;
use App\Models\Merchant;
use App\Models\User;

final class MerchantPolicy
{
    public function view(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'view');
    }

    public function counter(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'counter');
    }

    public function configure(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'configure');
    }

    public function develop(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'develop');
    }

    public function observe(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'observe');
    }

    public function team(User $user, Merchant $merchant): bool
    {
        return app(PanelAccess::class)->allows($user, $merchant->id, 'team');
    }
}
