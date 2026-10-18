<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Services;

use App\Domain\Merchants\Data\IssuedApiKey;
use App\Domain\Merchants\Enums\ApiKeyMode;
use App\Domain\Merchants\Enums\ApiKeyType;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\MerchantApiKey;
use App\Models\User;

final class ApiKeyService
{
    public function __construct(private TenantContext $tenants) {}

    public function issue(string $merchantId, string $userId, ApiKeyType $type, ApiKeyMode $mode): IssuedApiKey
    {
        $user = User::find($userId);
        if ($user === null || (! $user->is_platform_admin && ! $user->merchants()->whereKey($merchantId)->wherePivotIn('role', ['owner', 'developer'])->exists())) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        $prefix = ($type === ApiKeyType::SECRET ? 'sk_' : 'pk_').$mode->value.'_';
        $raw = $prefix.bin2hex(random_bytes(32));
        $key = $this->tenants->run($merchantId, fn () => MerchantApiKey::create(['merchant_id' => $merchantId, 'type' => $type, 'mode' => $mode, 'prefix' => $prefix, 'key_hash' => hash('sha256', $raw)]));

        return new IssuedApiKey($key->id, $raw);
    }
}
