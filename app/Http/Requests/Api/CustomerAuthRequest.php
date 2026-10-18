<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Customers\Data\CustomerAuthData;
use Illuminate\Foundation\Http\FormRequest;

final class CustomerAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mobileNumber' => $this->input('mobileNumber', $this->input('phoneNumber', '')),
            'name' => $this->input('name', $this->input('fullName', '')),
            'identifier' => $this->input('identifier', $this->input('email', $this->input('mobileNumber', $this->input('phoneNumber', '')))),
            'token' => $this->input('token', $this->input('sessionToken')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'check-user' => ['mobileNumber' => ['required', 'string', 'max:20']],
            'send-otp' => ['mobileNumber' => ['required', 'string', 'max:20'], 'name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'isNewUser' => ['sometimes', 'boolean']],
            'verify-otp' => ['mobileNumber' => ['required', 'string', 'max:20'], 'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/']],
            'register' => ['mobileNumber' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'password' => ['required', 'string', 'min:6', 'max:1024']],
            'login' => ['identifier' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024']],
            'switch-account' => ['token' => ['required', 'string', 'max:128']],
            default => [],
        };
    }

    public function toDto(): CustomerAuthData
    {
        $values = $this->validated();

        return new CustomerAuthData(
            operation: (string) $this->route('operation'),
            mobile: $values['mobileNumber'] ?? '',
            name: trim($values['name'] ?? ''),
            email: strtolower(trim($values['email'] ?? '')),
            password: $values['password'] ?? '',
            otp: $values['otp'] ?? '',
            identifier: trim($values['identifier'] ?? ''),
            isNewUser: (bool) ($values['isNewUser'] ?? false),
            token: $this->route('operation') === 'switch-account' ? $values['token'] : ($this->bearerToken() ?? $this->cookie('paykr_session')),
            ip: $this->ip() ?? '',
            userAgent: substr($this->userAgent() ?? '', 0, 1000),
        );
    }
}
