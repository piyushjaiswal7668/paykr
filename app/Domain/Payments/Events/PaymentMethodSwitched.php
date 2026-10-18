<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Enums\PaymentMethod;

final readonly class PaymentMethodSwitched
{
    public function __construct(public string $merchantId, public string $paymentId, public PaymentMethod $from, public PaymentMethod $to) {}
}
