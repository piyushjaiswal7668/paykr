<?php

declare(strict_types=1);

namespace App\Domain\Devices\Data;

final readonly class DeviceData
{
    /** @param string|array<string, mixed> $publicKey
     * @param  array<string, mixed>  $signals
     */
    public function __construct(public string $operation, public string $id = '', public string|array $publicKey = '', public array $signals = [], #[\SensitiveParameter] public ?string $token = null, public string $payload = '', public string $signature = '', #[\SensitiveParameter] public ?string $customerToken = null, public string $customerId = '', public ?string $userId = null) {}
}
