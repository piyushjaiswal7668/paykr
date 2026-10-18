<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    /** @param string|null $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null): string
    {
        return parent::getRateLimitKey($method, $component).':'.hash('sha256', strtolower(trim((string) ($this->data['email'] ?? ''))));
    }

    /** @param int $maxAttempts
     * @param  int  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        parent::rateLimit(config('paykr.rate_limits.login_attempts'), config('paykr.rate_limits.login_decay_seconds'), $method ?? 'authenticate', $component);
    }
}
