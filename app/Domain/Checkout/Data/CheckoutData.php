<?php

declare(strict_types=1);

namespace App\Domain\Checkout\Data;

use App\Domain\Payments\Enums\PaymentMethod;

final readonly class CheckoutData
{
    /** @param array<string, mixed> $details */
    public function __construct(public string $operation, public string $paymentId, public ?string $deviceId = null, #[\SensitiveParameter] public ?string $deviceToken = null, #[\SensitiveParameter] public ?string $customerToken = null, public PaymentMethod $method = PaymentMethod::UPI, public string $action = '', public array $details = [], public ?string $userId = null) {}
}
