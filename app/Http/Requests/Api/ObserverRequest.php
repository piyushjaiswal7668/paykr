<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Observers\Data\ObserverRequest as ObserverData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ObserverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route('operation') === 'pair') {
            return ['enrollmentToken' => ['required', 'string', 'max:256'], 'merchantId' => ['nullable', 'uuid'], 'deviceName' => ['required', 'string', 'max:255'], 'deviceModel' => ['nullable', 'string', 'max:255'], 'version' => ['sometimes', 'string', 'max:10'], 'expiresAt' => ['nullable', 'numeric', 'min:0']];
        }

        return ['body' => ['nullable', 'string', 'max:65536'], 'sms' => ['nullable', 'string', 'max:65536'], 'rawSms' => ['nullable', 'string', 'max:65536'], 'message' => ['nullable', 'string', 'max:65536'], 'text' => ['nullable', 'string', 'max:65536'], 'rawText' => ['nullable', 'string', 'max:65536'], 'sender' => ['nullable', 'string', 'max:255'], 'rawNotification' => ['nullable', 'array'], 'rawNotification.text' => ['nullable', 'string', 'max:65536'], 'rawNotification.package' => ['nullable', 'string', 'max:255'], 'receivingAccountId' => ['nullable', 'uuid'], 'paymentSessionId' => ['nullable', 'uuid'], 'observedAt' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                if (! is_string($value) && ! is_numeric($value)) {
                    $fail('Invalid observation timestamp.');

                    return;
                } is_numeric($value) ? CarbonImmutable::createFromTimestampMs($value) : CarbonImmutable::parse($value);
            } catch (\Throwable) {
                $fail('Invalid observation timestamp.');
            }
        }], 'isTest' => ['sometimes', 'boolean'], 'messageHash' => ['nullable', 'string', 'max:255'], 'merchantId' => ['nullable', 'uuid']];
    }

    public function toDto(): ObserverData
    {
        $v = $this->validated();
        $at = $v['observedAt'] ?? null;

        return new ObserverData(operation: (string) $this->route('operation'), deviceId: $this->header('X-Device-Id', ''), rawBody: $this->getContent(), timestamp: $this->header('X-Timestamp', ''), signature: $this->header('X-Signature', ''), enrollmentToken: $v['enrollmentToken'] ?? '', merchantId: $v['merchantId'] ?? null, deviceName: $v['deviceName'] ?? '', deviceModel: $v['deviceModel'] ?? '', version: $v['version'] ?? 'v1', expiresAt: empty($v['expiresAt']) ? null : CarbonImmutable::createFromTimestampMs($v['expiresAt']), text: $v['body'] ?? $v['sms'] ?? $v['rawSms'] ?? $v['message'] ?? $v['text'] ?? $v['rawText'] ?? $v['rawNotification']['text'] ?? '', sender: $v['sender'] ?? $v['rawNotification']['package'] ?? '', observedAt: $at === null ? null : (is_numeric($at) ? CarbonImmutable::createFromTimestampMs($at) : CarbonImmutable::parse($at)), accountId: $v['receivingAccountId'] ?? null, paymentId: $v['paymentSessionId'] ?? null, isTest: (bool) ($v['isTest'] ?? false), messageId: $v['messageHash'] ?? null);
    }
}
