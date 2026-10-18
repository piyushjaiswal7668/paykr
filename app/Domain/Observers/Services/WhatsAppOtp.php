<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

final class WhatsAppOtp
{
    /** @return array{success: bool, delivered: bool, queued: bool} */
    public function send(string $mobile, #[\SensitiveParameter] string $code, string $name, string $appName): array
    {
        $response = app(SidecarClient::class)->request('whatsapp', '/send-otp', ['to' => '91'.$mobile, 'code' => $code, 'name' => $name, 'appName' => $appName]);
        $delivered = ($response['delivered'] ?? false) === true;

        return ['success' => true, 'delivered' => $delivered, 'queued' => ! $delivered];
    }
}
