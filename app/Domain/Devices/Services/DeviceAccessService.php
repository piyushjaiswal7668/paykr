<?php

declare(strict_types=1);

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Models\Device;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeviceAccessService
{
    /** Terminal status is readable; every live bound session requires its device token.
     * @return array{allowed: bool, boundDeviceId: ?string, isTerminal: bool, deviceToken?: string}
     */
    public function check(string $paymentId, ?string $deviceId, #[\SensitiveParameter] ?string $token = null, bool $bind = true): array
    {
        return DB::transaction(function () use ($paymentId, $deviceId, $token, $bind): array {
            $payment = PaymentSession::whereKey($paymentId)->lockForUpdate()->first() ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
            $bound = $payment->device_id === null ? null : Device::find($payment->device_id);
            if ($payment->status->isTerminal()) {
                return ['allowed' => true, 'boundDeviceId' => $bound?->publicId(), 'isTerminal' => true];
            }
            $device = $deviceId === null ? null : Device::byPublicId($deviceId)->first();
            if ($payment->device_id !== null && ($device === null || $device->id !== $payment->device_id || ! $this->tokenValid($device, $token))) {
                throw new ValidationException(ErrorCode::DEVICE_SESSION_MISMATCH, legacy: [
                    'error' => 'DUPLICATE_SESSION_DETECTED', 'code' => 'DEVICE_SESSION_MISMATCH',
                    'session_id' => $payment->id, 'conflicting_session_id' => $payment->id, 'action' => 'END_SESSION_AND_CREATE_NEW',
                    'message' => 'This payment session was created on another device. For security reasons, this session cannot be continued from this device.',
                    'boundDeviceId' => $bound?->publicId(), 'requestingDeviceId' => $deviceId,
                ]);
            }
            $issuedToken = null;
            if ($bind && $deviceId !== null && $device === null) {
                if ($deviceId === '' || strlen($deviceId) > 255) {
                    throw new ValidationException(ErrorCode::DEVICE_REQUIRED);
                }
                $issuedToken = bin2hex(random_bytes(32));
                $device = new Device;
                $device->id = Str::isUuid($deviceId) ? $deviceId : (string) Str::uuid();
                $device->fill([
                    'legacy_id' => Str::isUuid($deviceId) ? null : $deviceId, 'public_key' => 'adhoc_'.$deviceId,
                    'fingerprint_hash' => hash('sha256', $deviceId), 'device_token_hash' => hash('sha256', $issuedToken), 'status' => DeviceStatus::ACTIVE, 'last_seen_at' => now()]);
                $device->save();
            } elseif ($device !== null && ! $this->tokenValid($device, $token)) {
                throw new ValidationException(ErrorCode::DEVICE_NOT_AUTHORIZED);
            }
            if ($device !== null) {
                if ($device->status !== DeviceStatus::ACTIVE) {
                    throw new ValidationException(ErrorCode::DEVICE_REVOKED);
                }
                if ($bind) {
                    $payment->update(['device_id' => $device->id]);
                    $device->update(['last_seen_at' => now()]);
                }
            }
            $result = ['allowed' => true, 'boundDeviceId' => $device?->publicId(), 'isTerminal' => false];
            if ($issuedToken !== null) {
                $result['deviceToken'] = $issuedToken;
            }

            return $result;
        }, 5);
    }

    public function tokenValid(Device $device, #[\SensitiveParameter] ?string $token): bool
    {
        return $token !== null && $token !== '' && $device->device_token_hash !== null && hash_equals($device->device_token_hash, hash('sha256', $token));
    }
}
