<?php

declare(strict_types=1);

namespace App\Domain\Realtime\Data;

final readonly class ChannelAccess
{
    public function __construct(public string $channel, public ?string $userId = null, public ?string $deviceId = null, #[\SensitiveParameter] public ?string $deviceToken = null, #[\SensitiveParameter] public ?string $customerToken = null) {}
}
