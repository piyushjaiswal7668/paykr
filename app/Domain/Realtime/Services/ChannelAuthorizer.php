<?php

declare(strict_types=1);

namespace App\Domain\Realtime\Services;

use App\Domain\Customers\Services\CustomerAuthService;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Services\DeviceAccessService;
use App\Domain\Realtime\Data\ChannelAccess;
use App\Domain\Shared\Errors\PaykrException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Device;
use App\Models\MerchantCustomerBlock;
use App\Models\PaymentSession;
use App\Models\User;

final class ChannelAuthorizer
{
    public function __construct(private TenantContext $tenants, private DeviceAccessService $devices, private CustomerAuthService $customers) {}

    public function allows(ChannelAccess $access): bool
    {
        if (preg_match('/^private-merchant\.([a-f0-9-]{36})\.live$/D', $access->channel, $match)) {
            return $access->userId !== null && (User::find($access->userId)?->merchants()->whereKey($match[1])->where('status', 'ACTIVE')->exists() ?? false);
        }
        if (! preg_match('/^private-payment\.([a-f0-9-]{36})$/D', $access->channel, $match)) {
            return false;
        }
        $payment = $this->tenants->runUnscoped(fn () => PaymentSession::find($match[1]));
        if ($payment === null) {
            return false;
        }
        $device = $access->deviceId === null ? null : Device::byPublicId($access->deviceId)->first();
        if ($device !== null && $device->id === $payment->device_id && $device->status === DeviceStatus::ACTIVE && $this->devices->tokenValid($device, $access->deviceToken)) {
            return true;
        }

        return $this->customerOwns($payment, $access->customerToken);
    }

    public function customerOwns(PaymentSession $payment, #[\SensitiveParameter] ?string $token): bool
    {
        if ($token === null || $token === '' || $payment->customer_id === null) {
            return false;
        }
        try {
            $customer = $this->customers->customerForToken($token);

            return $customer->id === $payment->customer_id && $this->tenants->run($payment->merchant_id, fn () => ! MerchantCustomerBlock::where('customer_id', $customer->id)->exists());
        } catch (PaykrException) {
            return false;
        }
    }
}
