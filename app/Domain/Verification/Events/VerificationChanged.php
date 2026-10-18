<?php

declare(strict_types=1);

namespace App\Domain\Verification\Events;

readonly class VerificationChanged
{
    public function __construct(public string $merchantId, public string $paymentId, public string $observationId, public int $verificationLevel, public string $verificationTag) {}
}
