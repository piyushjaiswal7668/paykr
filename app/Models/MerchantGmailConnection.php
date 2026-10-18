<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Observers\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\MerchantGmailConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable|null $token_expires_at
 * @property ConnectionStatus $status
 */
class MerchantGmailConnection extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantGmailConnectionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'merchant_gmail_connections';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['client_id', 'client_secret', 'refresh_token', 'access_token', 'legacy_api_key', 'token_expires_at', 'last_tested_at', 'last_successful_fetch', 'last_observation_at', 'connected_email', 'last_history_id', 'status', 'merchant_id'];

    protected $hidden = ['client_secret', 'refresh_token', 'access_token', 'legacy_api_key'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'legacy_api_key' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'last_tested_at' => 'immutable_datetime',
            'last_successful_fetch' => 'immutable_datetime',
            'last_observation_at' => 'immutable_datetime',
            'status' => ConnectionStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
