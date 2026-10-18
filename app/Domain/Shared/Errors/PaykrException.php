<?php

declare(strict_types=1);

namespace App\Domain\Shared\Errors;

use DomainException;

abstract class PaykrException extends DomainException
{
    /** @param array<string, mixed> $details
     * @param  array<string, mixed>  $legacy
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
        private readonly array $legacy = [],
    ) {
        parent::__construct($errorCode->value);
    }

    /** @return array<string, mixed> */
    public function legacy(): array
    {
        return $this->legacy;
    }
}
