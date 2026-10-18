<?php

declare(strict_types=1);

namespace App\Domain\Verification\Events;

final readonly class PaymentFraudBlocked
{
    public function __construct(public string $merchantId, public string $paymentId, public string $customerId) {}
}
