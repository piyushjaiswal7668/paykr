<?php

declare(strict_types=1);

namespace App\Domain\Realtime\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class RealtimeUpdate implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public string $connection = 'redis';

    public int $tries = 3;

    public int $timeout = 10;

    /** @param array<string, mixed> $payload */
    public function __construct(private string $channel, private string $name, private array $payload) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastQueue(): string
    {
        return 'payments';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
