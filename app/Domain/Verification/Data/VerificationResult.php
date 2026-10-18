<?php

declare(strict_types=1);

namespace App\Domain\Verification\Data;

final readonly class VerificationResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    public function isVerified(): bool
    {
        return $this->data['status'] === 'VERIFIED';
    }
}
