<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Observers\Data\GmailRequest as GmailData;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class GmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->route('operation') === 'callback' ? ['state' => ['required', 'string', 'max:8192'], 'code' => ['required_without:error', 'nullable', 'string', 'max:8192'], 'error' => ['sometimes', 'string', 'max:255']] : ['merchantId' => ['required', 'uuid']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
    }

    public function toDto(): GmailData
    {
        $v = $this->validated();

        return new GmailData((string) $this->route('operation'), (string) $this->user()->getAuthIdentifier(), $v['merchantId'] ?? '', $v['state'] ?? '', $v['code'] ?? '', isset($v['error']));
    }
}
