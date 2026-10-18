<?php

declare(strict_types=1);

namespace App\Domain\Verification\Data;

use Carbon\CarbonImmutable;

final readonly class VerificationData
{
    /** @param array<string, string> $submissionIdentity */
    public function __construct(
        public string $source,
        public string $rawText,
        public string $receivingAccountId,
        public ?string $sender = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?string $paymentSessionId = null,
        public ?string $customerId = null,
        public bool $isTest = false,
        public bool $testModeActive = false,
        public bool $suppressBan = false,
        public string $deviceInfo = '',
        public bool $isSimulated = false,
        public ?string $messageId = null,
        public array $submissionIdentity = [],
    ) {}
}
