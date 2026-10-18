<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

final readonly class PaymentActionTriggered
{
    public function __construct(public string $merchantId, public string $paymentId, public string $action) {}
}
