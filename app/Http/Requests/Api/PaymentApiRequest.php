<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Payments\Data\PaymentApiData;
use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PaymentApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['deviceId' => $this->input('deviceId', $this->header('X-Device-Id')), 'idempotencyKey' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route('operation') === 'booking-status') {
            return ['bookingStatus' => ['required', 'in:CONFIRMED,REJECTED,CANCELLED'], 'bookingVersion' => ['required', 'integer', 'min:0']];
        }
        if ($this->route('operation') === 'notification-preferences') {
            return ['enabled' => ['required', 'boolean']];
        }
        if (in_array($this->route('operation'), ['recheck', 'request-review'], true)) {
            return ['reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:2000']];
        }

        return $this->isMethod('POST') ? ['whatsappUpdatesConsent' => ['sometimes', 'boolean'], 'idempotencyKey' => ['nullable', 'string', 'max:255'], 'orderId' => ['required', 'string', 'max:200'], 'amount' => ['required', 'numeric', 'gt:0'], 'currency' => ['sometimes', Rule::in(['INR'])], 'method' => ['sometimes', Rule::enum(PaymentMethod::class)], 'customer' => ['sometimes', 'array'], 'customer.name' => ['nullable', 'string', 'max:255'], 'customer.email' => ['nullable', 'email', 'max:255'], 'customer.mobileNumber' => ['nullable', 'string', 'max:20'], 'customer.phoneNumber' => ['nullable', 'string', 'max:20'], 'customerId' => ['nullable', 'uuid'], 'deviceId' => ['nullable', 'string', 'max:255'], 'returnUrl' => ['nullable', 'url:http,https', 'max:2048'], 'metadata' => ['sometimes', 'array']] : [];
    }

    public function toDto(): PaymentApiData
    {
        if (! $this->isMethod('POST') || in_array($this->route('operation'), ['recheck', 'request-review', 'booking-status', 'notification-preferences'], true)) {
            return new PaymentApiData(identity: $this->attributes->get('api_identity'), operation: (string) $this->route('operation'), id: (string) $this->route('id', ''), orderId: (string) $this->route('orderId', ''), details: $this->validated());
        }

        return new PaymentApiData(identity: $this->attributes->get('api_identity'), operation: (string) $this->route('operation'), id: (string) $this->route('id', ''), orderId: (string) $this->route('orderId', $this->input('orderId', '')), amount: $this->string('amount')->toString(), method: PaymentMethod::from($this->input('method', 'UPI')), customerId: $this->input('customerId'), deviceId: $this->input('deviceId', $this->header('X-Device-Id')), returnUrl: $this->input('returnUrl'), idempotencyKey: $this->header('Idempotency-Key'), metadata: $this->input('metadata', []), customer: $this->input('customer', []), whatsappUpdatesConsent: $this->boolean('whatsappUpdatesConsent'));
    }
}
