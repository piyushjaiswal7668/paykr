<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

final class WhatsAppSidecar
{
    public function __construct(private readonly SidecarClient $client) {}

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return $this->client->request('whatsapp', '/status', get: true);
    }

    /** @return array<string, mixed>|null */
    public function connect(): ?array
    {
        return $this->client->request('whatsapp', '/connect');
    }

    /** @return array<string, mixed>|null */
    public function pairingCode(string $phoneNumber): ?array
    {
        return $this->client->request('whatsapp', '/pairing-code', ['phoneNumber' => $phoneNumber]);
    }

    /** @return array<string, mixed>|null */
    public function disconnect(): ?array
    {
        return $this->client->request('whatsapp', '/disconnect');
    }

    /** @return array<string, mixed>|null */
    public function send(string $to, string $message): ?array
    {
        return $this->client->request('whatsapp', '/send', ['to' => $to, 'message' => $message]);
    }
}
