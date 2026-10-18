<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Observers\Data\WhatsAppState;
use App\Domain\Observers\Enums\WhatsAppStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WhatsAppStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(WhatsAppStatus::class)], 'phoneNumber' => ['nullable', 'string', 'regex:/^\\+?[0-9]{10,15}$/']];
    }

    public function toDto(): WhatsAppState
    {
        return new WhatsAppState(WhatsAppStatus::from($this->validated('status')), $this->validated('phoneNumber'));
    }
}
