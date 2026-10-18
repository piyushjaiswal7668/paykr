<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Devices\Data\DeviceData;
use Illuminate\Foundation\Http\FormRequest;

final class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'register' => ['publicKey' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('Invalid public key.');
                }
            }], 'signals' => ['sometimes', 'array'], 'deviceToken' => ['nullable', 'string', 'max:128']],
            'verify' => ['deviceId' => ['required', 'string', 'max:255'], 'deviceToken' => ['nullable', 'string', 'max:128'], 'payload' => ['nullable', 'string', 'max:16384'], 'signature' => ['nullable', 'string', 'max:1024']],
            default => [],
        };
    }

    public function toDto(): DeviceData
    {
        $values = $this->validated();

        return new DeviceData(operation: (string) $this->route('operation'), id: (string) $this->route('id', $this->input('deviceId', '')), publicKey: $values['publicKey'] ?? '', signals: $values['signals'] ?? [], token: $values['deviceToken'] ?? $this->header('X-Device-Token'), payload: $values['payload'] ?? '', signature: $values['signature'] ?? '', customerToken: $this->bearerToken() ?? $this->cookie('paykr_session'), customerId: (string) $this->route('customerId', ''), userId: $this->isMethod('GET') ? $this->user('sanctum')?->getAuthIdentifier() : null);
    }
}
