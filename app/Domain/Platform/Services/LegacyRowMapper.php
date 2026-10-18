<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ImportException;
use App\Models\PaymentReceivingAccount;
use App\Models\SupportTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class LegacyRowMapper
{
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function map(Model $model, array $row): array
    {
        $table = $model->getTable();
        if ($table === 'devices') {
            $row['legacy_id'] = Str::isUuid($row['id']) ? null : $row['id'];
            $row['id'] = $this->deviceId($row['id']);
            $row['device_token_hash'] = isset($row['device_token']) ? hash('sha256', $row['device_token']) : null;
        }
        if ($table === 'payment_sessions' && isset($row['device_id'])) {
            $row['device_id'] = $this->deviceId($row['device_id']);
        }
        if ($table === 'customers') {
            $row['password_needs_rehash'] = isset($row['password_hash']) && preg_match('/^\d+:[^:]+:[^:]+$/D', $row['password_hash']) === 1;
        }
        if ($table === 'customer_sessions') {
            $row['token_hash'] = hash('sha256', $row['token']);
        }
        if ($table === 'android_devices') {
            $row['signing_key'] = $row['signing_key_hash'];
        }
        if ($table === 'payment_observations') {
            $row['merchant_id'] = PaymentReceivingAccount::whereKey($row['receiving_account_id'])->firstOrFail()->merchant_id;
        }
        if ($table === 'ticket_messages') {
            $row['merchant_id'] = SupportTicket::whereKey($row['ticket_id'])->firstOrFail()->merchant_id;
        }
        if ($table === 'audit_logs' && isset($row['actor_id']) && ! Str::isUuid($row['actor_id'])) {
            $details = $this->json($row['details'] ?? '{}');
            $details['legacy_actor_id'] = $row['actor_id'];
            $row['details'] = $details;
            $row['actor_id'] = null;
        }
        $attributes = array_intersect_key($row, array_flip([...$model->getFillable(), 'id', 'created_at', 'updated_at']));
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            $cast = $model->getCasts()[$key] ?? null;
            if ($cast === 'array') {
                $attributes[$key] = is_array($value) ? $value : $this->json($value);
            } elseif ($cast === 'immutable_datetime' || in_array($key, ['created_at', 'updated_at'], true)) {
                $attributes[$key] = self::date($value);
            }
        }

        return $attributes;
    }

    public function deviceId(string $id): string
    {
        return Str::isUuid($id) ? $id : Uuid::uuid5(Uuid::NAMESPACE_URL, 'paykr:legacy-device:'.$id)->toString();
    }

    /** @return array<string|int, mixed> */
    public function json(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new ImportException(ErrorCode::IMPORT_SOURCE_INVALID);
        }

        return $decoded;
    }

    public static function date(string|int|float $value): string
    {
        $date = is_numeric($value) ? CarbonImmutable::createFromTimestampMs($value, 'UTC') : CarbonImmutable::parse($value, 'UTC');

        return $date->utc()->format('Y-m-d H:i:s.v');
    }
}
