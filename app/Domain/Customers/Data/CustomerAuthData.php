<?php

declare(strict_types=1);

namespace App\Domain\Customers\Data;

final readonly class CustomerAuthData
{
    public function __construct(
        public string $operation,
        public string $mobile = '',
        public string $name = '',
        public string $email = '',
        #[\SensitiveParameter] public string $password = '',
        #[\SensitiveParameter] public string $otp = '',
        public string $identifier = '',
        public bool $isNewUser = false,
        #[\SensitiveParameter] public ?string $token = null,
        public string $ip = '',
        public string $userAgent = '',
    ) {}
}
