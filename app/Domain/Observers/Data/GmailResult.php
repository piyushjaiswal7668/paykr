<?php

declare(strict_types=1);

namespace App\Domain\Observers\Data;

final readonly class GmailResult
{
    public function __construct(public string $status, public ?string $redirect = null, public ?string $email = null) {}
}
