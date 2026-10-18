<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\OtpPurpose;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerOtpChallengeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $expires_at */
class CustomerOtpChallenge extends Model
{
    /** @use HasFactory<CustomerOtpChallengeFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'customer_otp_challenges';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['mobile_number', 'code_hash', 'purpose', 'pending_name', 'pending_email', 'ip_address', 'attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
