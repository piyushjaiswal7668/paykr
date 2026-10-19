<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

// Deterministic DNS answers for reserved test hosts; other callers retain native DNS.
function dns_get_record(string $host, int $type): array|false
{
    return match ($host) {
        'coverage-records.test' => [['ip' => '192.0.2.1'], ['ipv6' => '2001:db8::1'], ['ip' => '192.0.2.1'], ['type' => 'TXT']],
        'coverage-failure.test' => false,
        default => \dns_get_record($host, $type),
    };
}

it('extracts unique IPv4 and IPv6 addresses and handles DNS failures', function () {
    $resolver = new WebhookDnsResolver;
    expect($resolver->addresses('coverage-records.test'))->toBe(['192.0.2.1', '2001:db8::1']);
    expect($resolver->addresses('coverage-failure.test'))->toBe([]);
});
