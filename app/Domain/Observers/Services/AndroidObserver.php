<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Data\ObserverRequest;
use App\Domain\Observers\Data\ObserverResult;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\AndroidDevice;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\ObserverPairingToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AndroidObserver
{
    public function __construct(private TenantContext $tenants, private ObserverSignatures $signatures, private ObserverIntake $intake) {}

    public function issue(string $merchantId, string $userId): ObserverResult
    {
        $user = User::find($userId);
        if ($user === null || (! $user->is_platform_admin && ! $user->merchants()->whereKey($merchantId)->wherePivotIn('role', ['owner', 'manager', 'developer'])->exists())) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        Merchant::find($merchantId) ?? throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);
        $raw = 'pair_'.bin2hex(random_bytes(24));
        $token = $this->tenants->run($merchantId, fn () => ObserverPairingToken::create(['merchant_id' => $merchantId, 'created_by' => $userId, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(10)]));
        $url = rtrim(config('app.url'), '/');

        return new ObserverResult(['version' => 'v1', 'type' => 'PAYKR_SMS_GATEWAY_PAIR', 'serverUrl' => $url, 'gatewayUrl' => $url, 'apiBaseUrl' => $url, 'sessionId' => $token->id, 'token' => $raw, 'enrollmentToken' => $raw, 'merchantId' => $merchantId, 'createdAt' => now()->getTimestampMs(), 'expiresAt' => $token->expires_at->getTimestampMs()]);
    }

    public function execute(ObserverRequest $data): ObserverResult
    {
        if ($data->operation === 'pair') {
            return $this->pair($data);
        }
        $device = $this->tenants->runUnscoped(fn () => AndroidDevice::whereKey($data->deviceId)->where('is_active', true)->first()) ?? throw new ValidationException(ErrorCode::DEVICE_NOT_AUTHORIZED);

        return $this->tenants->run($device->merchant_id, function () use ($device, $data): ObserverResult {
            // Existing APK sends unsigned heartbeats; they cannot submit observations.
            if ($data->operation === 'heartbeat' && $data->signature === '' && $data->timestamp === '') {
                $device->update(['last_heartbeat_at' => now()]);

                return new ObserverResult(['status' => 'HEARTBEAT_ACK', 'timestamp' => now()->getTimestampMs()]);
            }

            return DB::transaction(function () use ($device, $data): ObserverResult {
                $device = AndroidDevice::whereKey($device->id)->where('is_active', true)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::DEVICE_NOT_AUTHORIZED);
                $hash = $this->signatures->verify('android:'.$device->id, $device->signing_key, $data->rawBody, $data->timestamp, $data->signature);
                $this->signatures->consume($hash);
                if ($data->operation === 'heartbeat') {
                    $device->update(['last_heartbeat_at' => now()]);

                    return new ObserverResult(['status' => 'HEARTBEAT_ACK', 'timestamp' => now()->getTimestampMs()]);
                }
                $result = $this->intake->process($data, 'SMS', $device->device_name.' ('.$device->device_model.')');
                $device->update(['last_observation_at' => now()]);

                return $result;
            }, 5);
        });
    }

    private function pair(ObserverRequest $data): ObserverResult
    {
        if ($data->version !== 'v1') {
            throw new ValidationException(ErrorCode::PAIRING_QR_INVALID, legacy: ['error' => 'INVALID Paykr PAIRING QR']);
        }
        if ($data->expiresAt !== null && $data->expiresAt->lessThanOrEqualTo(now())) {
            throw new ValidationException(ErrorCode::PAIRING_QR_EXPIRED, legacy: ['error' => 'PAIRING QR EXPIRED']);
        }
        $token = $this->tenants->runUnscoped(fn () => ObserverPairingToken::where('token_hash', hash('sha256', $data->enrollmentToken))->first());
        $dev = app()->environment(['local', 'testing']) && is_string(config('paykr.android.dev_enrollment_secret')) && config('paykr.android.dev_enrollment_secret') !== '' && hash_equals(config('paykr.android.dev_enrollment_secret'), $data->enrollmentToken);
        $merchantId = $token === null ? ($dev ? $data->merchantId : null) : $token->merchant_id;
        if ($merchantId === null || ! Merchant::whereKey($merchantId)->where('status', 'ACTIVE')->exists()) {
            throw new ValidationException(ErrorCode::PAIRING_TOKEN_INVALID);
        }

        return $this->tenants->run($merchantId, fn () => DB::transaction(function () use ($data, $token, $merchantId): ObserverResult {
            if ($token !== null) {
                $token = ObserverPairingToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
                if ($token->consumed_at !== null) {
                    throw new ValidationException(ErrorCode::PAIRING_TOKEN_INVALID);
                }
                if ($token->expires_at->lessThanOrEqualTo(now())) {
                    throw new ValidationException(ErrorCode::PAIRING_QR_EXPIRED);
                }
            }
            $key = bin2hex(random_bytes(32));
            $device = AndroidDevice::create(['merchant_id' => $merchantId, 'device_name' => $data->deviceName, 'device_model' => $data->deviceModel, 'signing_key' => $key, 'is_active' => true, 'last_heartbeat_at' => now()]);
            $token?->update(['consumed_at' => now(), 'consumed_by_device_id' => $device->id]);
            AuditLog::create(['merchant_id' => $merchantId, 'entity_type' => 'ANDROID_DEVICE', 'entity_id' => $device->id, 'action' => 'SMS_GATEWAY_DEVICE_PAIRED', 'actor_type' => 'DEVICE', 'actor_id' => $device->id]);

            return new ObserverResult(['paired' => true, 'deviceId' => $device->id, 'signingKey' => $key, 'merchantId' => $merchantId]);
        }, 5));
    }
}
