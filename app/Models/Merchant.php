<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Merchants\Enums\MerchantStatus;
use Database\Factories\MerchantFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property MerchantStatus $status
 * @property array<string>|null $allowed_origins
 */
class Merchant extends Model implements HasName
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['whatsapp_merchant_enabled', 'whatsapp_customer_enabled', 'workflow_webhooks_enabled', 'whatsapp_phone', 'business_name', 'slug', 'contact_email', 'contact_phone', 'status', 'webhook_url', 'webhook_secret', 'allowed_origins'];

    protected $hidden = ['webhook_secret', 'legacy_settings'];

    public function getFilamentName(): string
    {
        return $this->business_name;
    }

    protected function casts(): array
    {
        return ['whatsapp_merchant_enabled' => 'boolean', 'whatsapp_customer_enabled' => 'boolean', 'workflow_webhooks_enabled' => 'boolean', 'status' => MerchantStatus::class, 'webhook_secret' => 'encrypted', 'allowed_origins' => 'array', 'legacy_settings' => 'encrypted:array'];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'merchant_users')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<PaymentReceivingAccount, $this> */
    public function receivingAccounts(): HasMany
    {
        return $this->hasMany(PaymentReceivingAccount::class, 'merchant_id');
    }

    /** @return HasMany<PaymentSession, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentSession::class, 'merchant_id');
    }

    /** @return HasMany<PaymentObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(PaymentObservation::class, 'merchant_id');
    }

    /** @return HasMany<AndroidDevice, $this> */
    public function androidDevices(): HasMany
    {
        return $this->hasMany(AndroidDevice::class, 'merchant_id');
    }

    /** @return HasMany<MerchantApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(MerchantApiKey::class, 'merchant_id');
    }

    /** @return HasOne<MerchantBranding, $this> */
    public function branding(): HasOne
    {
        return $this->hasOne(MerchantBranding::class, 'merchant_id');
    }

    /** @return HasOne<MerchantPaymentSettings, $this> */
    public function paymentSettings(): HasOne
    {
        return $this->hasOne(MerchantPaymentSettings::class, 'merchant_id');
    }

    /** @return HasOne<MerchantGmailConnection, $this> */
    public function gmailConnection(): HasOne
    {
        return $this->hasOne(MerchantGmailConnection::class, 'merchant_id');
    }

    /** @return HasOne<MerchantTestMode, $this> */
    public function testMode(): HasOne
    {
        return $this->hasOne(MerchantTestMode::class, 'merchant_id');
    }
}
