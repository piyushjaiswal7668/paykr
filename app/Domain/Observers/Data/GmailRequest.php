<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

final readonly class GmailRequest
{
    public function __construct(public string $operation, public string $userId, public string $merchantId = '', #[\SensitiveParameter] public string $state = '', #[\SensitiveParameter] public string $code = '', public bool $denied = false) {}
}
