<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Data\MessagesHealth;
use Carbon\CarbonImmutable;
use Throwable;

final class MessagesSidecar
{
    public function __construct(private readonly SidecarClient $client) {}

    /** @return array<string, mixed>|null */
    public function pair(string $merchantId): ?array
    {
        return $this->client->request('messages', '/profiles/'.rawurlencode($merchantId).'/pair');
    }

    public function sweep(string $merchantId): void
    {
        $this->client->request('messages', '/profiles/'.rawurlencode($merchantId).'/sweep');
    }

    public function health(string $merchantId): ?MessagesHealth
    {
        $data = $this->client->request('messages', '/profiles/'.rawurlencode($merchantId).'/status', get: true);
        if ($data === null) {
            return null;
        }
        try {
            return new MessagesHealth($merchantId, CarbonImmutable::now(), ($data['isPaired'] ?? false) === true, is_string($data['status'] ?? null) ? $data['status'] : 'STANDBY', is_string($data['mode'] ?? null) ? $data['mode'] : 'STANDBY', false, is_string($data['lastSeenAt'] ?? null) ? CarbonImmutable::parse($data['lastSeenAt']) : null);
        } catch (Throwable) {
            return null;
        }
    }
}
