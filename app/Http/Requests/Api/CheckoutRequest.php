<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Checkout\Data\CheckoutData;
use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'simulate-observation' => ['source' => ['sometimes', 'string', 'in:SMS,GMAIL,EMAIL'], 'rawText' => ['required', 'string', 'max:65536'], 'sender' => ['nullable', 'string', 'max:255']],
            'notification-preferences' => ['enabled' => ['required', 'boolean']],
            'request-review' => ['reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:2000']],
            'server-health' => ['merchantId' => ['required', 'uuid']],
            'select-method' => ['method' => ['required', Rule::enum(PaymentMethod::class)]],
            'trigger-hook' => ['action' => ['required', 'string', 'regex:/^[A-Z_]{2,40}$/D'], 'details' => ['sometimes', 'array']],
            default => [],
        };
    }

    public function toDto(): CheckoutData
    {
        return new CheckoutData(operation: (string) $this->route('operation'), paymentId: (string) $this->route('id', $this->input('merchantId', '')), deviceId: $this->header('X-Device-Id'), deviceToken: $this->header('X-Device-Token'), customerToken: $this->bearerToken() ?? $this->cookie('paykr_session'), method: $this->route('operation') === 'select-method' ? PaymentMethod::from($this->input('method')) : PaymentMethod::UPI, action: $this->route('operation') === 'trigger-hook' ? $this->string('action')->toString() : '', details: in_array($this->route('operation'), ['simulate-observation', 'request-review', 'notification-preferences'], true) ? $this->validated() : ($this->route('operation') === 'trigger-hook' ? $this->input('details', []) : []), userId: $this->route('operation') === 'simulate-observation' ? $this->user('sanctum')?->getAuthIdentifier() : null);
    }
}
