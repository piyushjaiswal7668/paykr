<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Merchants\Enums\MerchantStatus;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/** @property array<string>|null $two_factor_recovery_codes */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['name', 'email', 'password', 'timezone'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_platform_admin' => 'boolean', 'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => (bool) $this->is_platform_admin,
            'merchant' => $this->merchants()->where('status', MerchantStatus::ACTIVE)->exists(),
            default => false,
        };
    }

    /** @return BelongsToMany<Merchant, $this> */
    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'merchant_users')->withPivot('role')->withTimestamps();
    }

    /** @return Collection<int, Merchant> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->merchants()->where('status', MerchantStatus::ACTIVE)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Merchant && $this->merchants()->whereKey($tenant->getKey())->where('status', MerchantStatus::ACTIVE)->exists();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->two_factor_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /** @param array<string>|null $codes */
    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->two_factor_recovery_codes = $codes;
        $this->save();
    }
}
