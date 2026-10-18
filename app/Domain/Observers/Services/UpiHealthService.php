<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Merchants\Enums\SellingMode;
use App\Domain\Observers\Data\MessagesHealth;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\AndroidDevice;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantPaymentSettings;

final class UpiHealthService
{
    /** @return array<string, mixed> */
    public function check(?MessagesHealth $messages = null): array
    {
        $merchantId = app(TenantContext::class)->merchantId();
        if ($merchantId === null) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        $messages ??= app(MessagesSidecar::class)->health($merchantId);
        $now = now()->toImmutable();
        $cutoff = $now->subMinutes(5)->format('Y-m-d H:i:s.v');
        $sms = AndroidDevice::where('is_active', true)->where('last_heartbeat_at', '>', $cutoff)->where('last_heartbeat_at', '<=', $now->format('Y-m-d H:i:s.v'))->exists();
        if (! $sms && $messages !== null && $messages->merchantId === $merchantId && $messages->checkedAt->greaterThan($now->subMinutes(5)) && $messages->checkedAt->lessThanOrEqualTo($now)) {
            $recent = $messages->lastSeenAt !== null && $messages->lastSeenAt->greaterThan($now->subMinutes(5)) && $messages->lastSeenAt->lessThanOrEqualTo($now);
            $sms = $messages->paired && $messages->status === 'CONNECTED' && $messages->mode === 'BACKGROUND' && ($messages->polling || $recent);
        }
        $connection = MerchantGmailConnection::where('last_successful_fetch', '>', $cutoff)->where('last_successful_fetch', '<=', $now->format('Y-m-d H:i:s.v'))->first();
        $gmail = $connection !== null && trim($connection->refresh_token ?? '') !== '';
        $settings = MerchantPaymentSettings::first();
        $cash = $settings !== null && ($settings->selling_mode === SellingMode::ONLINE ? $settings->cod_enabled : $settings->cash_enabled);
        $tier = $sms && $gmail ? 'GREEN' : ($sms || $gmail ? 'YELLOW' : 'RED');

        return [
            'status' => match ($tier) {
                'GREEN' => 'WORKING', 'YELLOW' => 'BUSY', default => 'ISSUES'
            },
            'tier' => $tier, 'label' => match ($tier) {
                'GREEN' => 'Server Is Working', 'YELLOW' => 'UPI Server Busy', default => 'Server Facing Issues'
            },
            'isWorking' => $tier !== 'RED', 'reason' => 'SMS '.($sms ? 'healthy' : 'unavailable').'; Gmail '.($gmail ? 'healthy' : 'unavailable'),
            'smsHealthy' => $sms, 'gmailHealthy' => $gmail,
            'warning' => match ($tier) {
                'GREEN' => null, 'YELLOW' => 'Your payment may go through extra processing seconds.', default => 'It is not recommended to pay by UPI right now as the server is facing downtime. We recommend paying by cash instead.'
            },
            'recommendedAlternative' => $tier === 'RED' && $cash ? 'CASH' : null,
        ];
    }
}
