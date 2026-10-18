<?php

declare(strict_types=1);

namespace App\Domain\Devices\Services;

use App\Domain\Customers\Services\CustomerAuthService;
use App\Domain\Devices\Data\DeviceData;
use App\Domain\Devices\Data\DeviceResult;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DeviceService
{
    public function __construct(private DeviceAccessService $access, private CustomerAuthService $customers) {}

    public function execute(DeviceData $data): DeviceResult
    {
        if ($data->operation === 'register') {
            return $this->register($data);
        }
        $admin = $data->userId !== null && User::whereKey($data->userId)->where('is_platform_admin', true)->exists();
        if ($data->operation === 'customer' && $admin) {
            return new DeviceResult(['devices' => Device::where('customer_id', $data->customerId)->get()->map(fn (Device $device) => $this->summary($device))->all()]);
        }
        if ($data->operation === 'customer') {
            $customer = $this->customers->customerForToken($data->customerToken);
            if ($customer->id !== $data->customerId) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }

            return new DeviceResult(['devices' => Device::where('customer_id', $customer->id)->get()->map(fn (Device $device) => $this->summary($device))->all()]);
        }
        $device = Device::byPublicId($data->id)->first() ?? throw new ValidationException(ErrorCode::NOT_FOUND);
        if ($data->operation === 'verify') {
            $tokenValid = $device->status === DeviceStatus::ACTIVE && $this->access->tokenValid($device, $data->token);
            $signatureValid = $device->status === DeviceStatus::ACTIVE && $data->payload !== '' && DeviceSecurity::verifySignature($device->public_key, $data->payload, $data->signature);

            return new DeviceResult(['valid' => $tokenValid || $signatureValid, 'tokenValid' => $tokenValid, 'signatureValid' => $signatureValid]);
        }
        if ($admin) {
            return new DeviceResult($this->summary($device));
        }
        $customer = $this->customers->customerForToken($data->customerToken);
        if ($device->customer_id !== $customer->id) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return new DeviceResult($this->summary($device));
    }

    private function register(DeviceData $data): DeviceResult
    {
        $key = DeviceSecurity::canonicalPublicKey($data->publicKey) ?? throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        $lock = Cache::lock('device-register:'.hash('sha256', $key), 10);
        if (! $lock->get()) {
            throw new ValidationException(ErrorCode::RATE_LIMITED);
        }
        try {
            return DB::transaction(function () use ($key, $data): DeviceResult {
                $device = Device::where('public_key', $key)->lockForUpdate()->first();
                $existing = $device !== null;
                $token = null;
                if ($device === null) {
                    $token = bin2hex(random_bytes(32));
                    $device = Device::create(['public_key' => $key, 'fingerprint_hash' => DeviceSecurity::fingerprint($data->signals), 'device_token_hash' => hash('sha256', $token), 'status' => DeviceStatus::ACTIVE, 'last_seen_at' => now()]);
                } elseif ($this->access->tokenValid($device, $data->token)) {
                    $token = $data->token;
                    $device->update(['last_seen_at' => now()]);
                }

                return new DeviceResult($this->summary($device) + ['deviceToken' => $token, 'isExisting' => $existing]);
            });
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function summary(Device $device): array
    {
        return ['deviceId' => $device->publicId(), 'fingerprintHash' => $device->fingerprint_hash, 'status' => $device->status->value];
    }
}
