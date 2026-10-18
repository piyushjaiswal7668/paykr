<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookUrlPolicy
{
    public function __construct(private WebhookDnsResolver $dns) {}

    /** @return array<string, mixed> */
    public function requestOptions(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! filter_var($url, FILTER_VALIDATE_URL) || ! isset($parts['host'], $parts['scheme'])
            || ! in_array($parts['scheme'], ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
        }
        $options = ['allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_PROXY => '']];
        if (app()->environment(['local', 'testing'])) {
            return $options;
        }
        if ($parts['scheme'] !== 'https') {
            throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
        }
        $host = trim($parts['host'], '[]');
        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || IpUtils::isPrivateIp($address) || IpUtils::checkIp($address, ['224.0.0.0/4', 'ff00::/8'])) {
                throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
            }
        }
        // Pin the validated address for this request; DNS cannot rebind between validation and send.
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $options['curl'][CURLOPT_RESOLVE] = [$parts['host'].':'.($parts['port'] ?? 443).':'.$address];
        $options['curl'][CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;

        return $options;
    }
}
