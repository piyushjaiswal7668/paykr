<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

use Carbon\CarbonImmutable;

final readonly class ObserverRequest
{
    public function __construct(public string $operation, public string $deviceId = '', public string $rawBody = '', public string $timestamp = '', #[\SensitiveParameter] public string $signature = '', #[\SensitiveParameter] public string $enrollmentToken = '', public ?string $merchantId = null, public string $deviceName = '', public string $deviceModel = '', public string $version = 'v1', public ?CarbonImmutable $expiresAt = null, public string $text = '', public string $sender = '', public ?CarbonImmutable $observedAt = null, public ?string $accountId = null, public ?string $paymentId = null, public bool $isTest = false, public ?string $messageId = null) {}
}
