<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class PairingTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function merchantId(): string
    {
        return (string) $this->route('merchantId');
    }

    public function actorId(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }
}
