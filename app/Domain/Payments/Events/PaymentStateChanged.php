<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Enums\PaymentStatus;

readonly class PaymentStateChanged
{
    public function __construct(
        public string $merchantId,
        public string $paymentId,
        public ?string $receivingAccountId,
        public PaymentStatus $from,
        public PaymentStatus $status,
    ) {}
}
