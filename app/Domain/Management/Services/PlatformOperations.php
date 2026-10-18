<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Customers\Services\BanService;
use App\Domain\Management\Data\PanelCommand;
use App\Domain\Observers\Services\WhatsAppSidecar;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Webhooks\Services\WebhookService;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

final class PlatformOperations
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants) {}

    /** @return array<string, mixed> */
    public function execute(PanelCommand $command): array
    {
        $actor = $this->access->admin($command->userId, $command->ip);

        return $this->tenants->runUnscoped(fn () => DB::transaction(function () use ($command, $actor): array {
            $result = match ($command->operation) {
                'merchant-save' => $this->merchant($command),
                'merchant-suspend', 'merchant-activate' => $this->merchantStatus($command),
                'customer-ban', 'customer-unban' => $this->ban($command, $actor),
                'payment-close' => $this->close($command, $actor),
                'webhook-resend' => $this->resend($command),
                'key-revoke' => $this->revoke($command),
                'impersonate' => $this->impersonate($command),
                'whatsapp-connect', 'whatsapp-disconnect', 'whatsapp-pairing-code', 'whatsapp-test' => $this->whatsapp($command),
                default => throw new ValidationException(ErrorCode::NOT_FOUND),
            };
            $this->access->audit($actor, 'ADMIN_'.strtoupper(str_replace('-', '_', $command->operation)), 'PLATFORM_OPERATION', $command->recordId ?? $result['merchantId'] ?? null,
                isset($command->data['notes']) ? ['notes' => $command->data['notes']] : []);

            return $result;
        }));
    }

    /** @return array<string, mixed> */
    private function merchant(PanelCommand $command): array
    {
        $rules = ['business_name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'max:100'], 'contact_email' => ['required', 'email', 'max:254'], 'contact_phone' => ['nullable', 'string', 'max:30']];
        if ($command->recordId === null) {
            $rules += ['owner_name' => ['required', 'string', 'max:100'], 'owner_email' => ['required', 'email', 'max:254']];
        }
        $data = Validator::make($command->data, $rules)->validate();
        if (Merchant::where('slug', $data['slug'])->when($command->recordId, fn ($q) => $q->whereKeyNot($command->recordId))->exists()) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }

        return DB::transaction(function () use ($command, $data): array {
            if ($command->recordId !== null) {
                Merchant::findOrFail($command->recordId)->update($data);

                return ['status' => 'UPDATED'];
            }
            $merchant = Merchant::create(array_intersect_key($data, array_flip(['business_name', 'slug', 'contact_email', 'contact_phone'])) + ['status' => 'ACTIVE', 'webhook_secret' => bin2hex(random_bytes(32)), 'allowed_origins' => []]);
            $user = User::where('email', $data['owner_email'])->first();
            $new = $user === null;
            if ($new) {
                $user = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => bin2hex(random_bytes(32))]);
            }
            if ($user->is_platform_admin) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            $merchant->users()->attach($user->id, ['role' => 'owner']);
            $result = ['merchantId' => $merchant->id, 'userId' => $user->id];
            if ($new) {
                $result['invitationToken'] = Password::broker()->createToken($user);
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    private function merchantStatus(PanelCommand $command): array
    {
        Merchant::findOrFail($command->recordId)->update(['status' => $command->operation === 'merchant-suspend' ? 'SUSPENDED' : 'ACTIVE']);

        return ['status' => 'UPDATED'];
    }

    /** @return array<string, mixed> */
    private function ban(PanelCommand $command, Actor $actor): array
    {
        $data = Validator::make($command->data, ['notes' => ['required', 'string', 'min:3', 'max:2000']])->validate();
        $service = app(BanService::class);
        if ($command->operation === 'customer-ban') {
            $service->ban($command->recordId ?? '', $actor, $data['notes']);
        } else {
            $service->unban($command->recordId ?? '', $actor, $data['notes']);
        }

        return ['status' => 'UPDATED'];
    }

    /** @return array<string, mixed> */
    private function close(PanelCommand $command, Actor $actor): array
    {
        $payment = PaymentSession::findOrFail($command->recordId);
        $this->tenants->run($payment->merchant_id, fn () => app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, $actor));

        return ['status' => 'CANCELLED'];
    }

    /** @return array<string, mixed> */
    private function resend(PanelCommand $command): array
    {
        $delivery = WebhookDelivery::findOrFail($command->recordId);
        $delivery->update(['is_successful' => false, 'attempt_count' => 0, 'next_retry_at' => now()]);
        app(WebhookService::class)->schedule($delivery);

        return ['status' => 'QUEUED'];
    }

    /** @return array<string, mixed> */
    private function revoke(PanelCommand $command): array
    {
        MerchantApiKey::findOrFail($command->recordId)->update(['revoked_at' => now()]);

        return ['status' => 'REVOKED'];
    }

    /** @return array<string, mixed> */
    private function impersonate(PanelCommand $command): array
    {
        $merchant = Merchant::whereKey($command->recordId)->where('status', 'ACTIVE')->firstOrFail();
        $user = $merchant->users()->wherePivot('role', 'owner')->where('is_platform_admin', false)->firstOrFail();

        return ['userId' => $user->id, 'merchantSlug' => $merchant->slug];
    }

    /** @return array<string, mixed> */
    private function whatsapp(PanelCommand $command): array
    {
        $service = app(WhatsAppSidecar::class);
        if ($command->operation === 'whatsapp-pairing-code') {
            $data = Validator::make($command->data, ['phone' => ['required', 'regex:/^[0-9]{10,15}$/D']])->validate();
            $result = $service->pairingCode($data['phone']);
        } elseif ($command->operation === 'whatsapp-test') {
            $data = Validator::make($command->data, ['phone' => ['required', 'regex:/^[0-9]{10,15}$/D'], 'message' => ['required', 'string', 'max:1000']])->validate();
            $result = $service->send($data['phone'], $data['message']);
        } else {
            $result = $command->operation === 'whatsapp-connect' ? $service->connect() : $service->disconnect();
        }
        if ($result === null) {
            throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
        }

        return array_intersect_key($result, array_flip(['status', 'isConnected', 'qrCode', 'pairingCode', 'pairingPhone', 'phoneNumber', 'success', 'delivered', 'queued']));
    }
}
