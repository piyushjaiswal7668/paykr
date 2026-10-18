<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Services;

use App\Domain\Merchants\Data\ApiIdentity;
use App\Domain\Merchants\Enums\ApiKeyMode;
use App\Domain\Merchants\Enums\ApiKeyType;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantApiKey;

final class ApiKeyAuthenticator
{
    public function __construct(private TenantContext $tenants) {}

    public function authenticate(#[\SensitiveParameter] ?string $raw, ?string $origin, bool $secretOnly, ?string $legacyMerchant = null): ApiIdentity
    {
        if (($raw === null || $raw === '') && config('paykr.allow_legacy_merchant_header') && $legacyMerchant !== null) {
            $merchant = Merchant::find($legacyMerchant) ?? throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);
            if ($merchant->status !== MerchantStatus::ACTIVE) {
                throw new ValidationException(ErrorCode::MERCHANT_SUSPENDED);
            }
            if ($secretOnly) {
                throw new ValidationException(ErrorCode::INVALID_API_KEY);
            }

            return new ApiIdentity($merchant->id, false, false);
        }
        if ($raw === null || ! preg_match('/^(pk|sk)_(live|test)_[a-zA-Z0-9]{32,}$/D', $raw, $parts)) {
            throw new ValidationException(ErrorCode::INVALID_API_KEY);
        }
        $key = $this->tenants->runUnscoped(fn () => MerchantApiKey::where('key_hash', hash('sha256', $raw))->whereNull('revoked_at')->first());
        if ($key === null || ($key->type === ApiKeyType::SECRET ? 'sk' : 'pk') !== $parts[1] || $key->mode->value !== $parts[2] || ($secretOnly && $key->type !== ApiKeyType::SECRET)) {
            throw new ValidationException(ErrorCode::INVALID_API_KEY);
        }
        $merchant = Merchant::find($key->merchant_id) ?? throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);
        if ($merchant->status !== MerchantStatus::ACTIVE) {
            throw new ValidationException(ErrorCode::MERCHANT_SUSPENDED);
        }
        if ($key->type === ApiKeyType::PUBLISHABLE && ($origin === null || ! in_array($origin, $merchant->allowed_origins ?? [], true))) {
            throw new ValidationException(ErrorCode::ORIGIN_NOT_ALLOWED);
        }
        $this->tenants->run($merchant->id, fn () => $key->update(['last_used_at' => now()]));

        return new ApiIdentity($merchant->id, $key->mode === ApiKeyMode::TEST, $key->type === ApiKeyType::SECRET);
    }
}
