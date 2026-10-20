<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

use App\Domain\Merchants\Data\ApiIdentity;
use App\Domain\Payments\Enums\PaymentMethod;

final readonly class PaymentApiData
{
    /** @param array<string, mixed> $metadata
     * @param array<string, mixed> $details
     * @param array<string, string> $customer */
    public function __construct(public ApiIdentity $identity, public string $operation, public string $id = '', public string $orderId = '', public string $amount = '', public PaymentMethod $method = PaymentMethod::UPI, public ?string $customerId = null, public ?string $deviceId = null, public ?string $returnUrl = null, public ?string $idempotencyKey = null, public array $metadata = [], public array $customer = [], public array $details = [], public bool $whatsappUpdatesConsent = false) {}
}
