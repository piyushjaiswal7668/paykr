<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Merchants\Enums\ApiKeyMode;
use App\Domain\Merchants\Enums\ApiKeyType;
use App\Domain\Merchants\Services\ApiKeyService;
use App\Domain\Observations\Services\BankSmsParser;
use App\Domain\Observers\Data\GmailRequest;
use App\Domain\Observers\Services\AndroidObserver;
use App\Domain\Observers\Services\GmailOAuth;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Domain\Webhooks\Services\WebhookService;
use App\Domain\Webhooks\Services\WebhookUrlPolicy;
use App\Models\AndroidDevice;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantGmailConnection;
use App\Models\MerchantTestMode;
use App\Models\PaymentSession;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class DeveloperTools
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants) {}

    /** @return array<string, mixed> */
    public function execute(PanelCommand $command): array
    {
        $capability = str_starts_with($command->operation, 'android-') || str_starts_with($command->operation, 'gmail-') || $command->operation === 'parse-sms' ? 'observe' : 'develop';
        $actor = $this->access->authorize($command, $capability);

        return $this->tenants->run($command->merchantId, fn () => DB::transaction(function () use ($command, $actor): array {
            $result = match ($command->operation) {
                'android-pair' => app(AndroidObserver::class)->issue($command->merchantId, $command->userId)->data,
                'android-unpair' => $this->unpair($command),
                'parse-sms' => $this->parse($command),
                'gmail-connect', 'gmail-test', 'gmail-disconnect' => $this->gmail($command),
                'gmail-credentials' => $this->gmailCredentials($command),
                'webhook-settings', 'webhook-rotate', 'webhook-reveal', 'webhook-resend', 'webhook-test' => $this->webhooks($command),
                'key-issue', 'key-revoke', 'key-rotate' => $this->keys($command),
                'test-enable', 'test-disable' => $this->testMode($command),
                'simulate' => $this->simulate($command),
                default => throw new ValidationException(ErrorCode::NOT_FOUND),
            };
            $this->access->audit($actor, strtoupper(str_replace('-', '_', $command->operation)), 'DEVELOPER_OPERATION', $command->recordId ?? $command->merchantId,
                $command->operation === 'simulate' ? ['result' => $result] : []);

            return $result;
        }));
    }

    /** @return array<string, mixed> */
    private function unpair(PanelCommand $command): array
    {
        AndroidDevice::findOrFail($command->recordId)->update(['is_active' => false]);

        return ['status' => 'UNPAIRED'];
    }

    /** @return array<string, mixed> */
    private function parse(PanelCommand $command): array
    {
        $data = Validator::make($command->data, ['text' => ['required', 'string', 'max:65536']])->validate();
        $parsed = BankSmsParser::parse($data['text']);

        return ['parsed' => $parsed !== null, 'amountPaise' => $parsed?->amountPaise, 'reference' => $parsed?->bankReferenceNumber, 'senderVpa' => $parsed?->senderVpa, 'attributed' => false];
    }

    /** @return array<string, mixed> */
    private function gmail(PanelCommand $command): array
    {
        $result = app(GmailOAuth::class)->execute(new GmailRequest(substr($command->operation, 6), $command->userId, $command->merchantId));

        return ['status' => $result->status, 'redirect' => $result->redirect, 'email' => $result->email];
    }

    /** @return array<string, mixed> */
    private function gmailCredentials(PanelCommand $command): array
    {
        $data = Validator::make($command->data, ['client_id' => ['required', 'string', 'max:500'], 'client_secret' => ['required', 'string', 'max:500']])->validate();
        MerchantGmailConnection::updateOrCreate(['merchant_id' => $command->merchantId], $data);

        return ['status' => 'SAVED'];
    }

    /** @return array<string, mixed> */
    private function keys(PanelCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            Merchant::whereKey($command->merchantId)->lockForUpdate()->firstOrFail();
            if ($command->operation === 'key-issue') {
                $data = Validator::make($command->data, ['type' => ['required', 'in:publishable,secret'], 'mode' => ['required', 'in:live,test']])->validate();
                $type = ApiKeyType::from($data['type']);
                $mode = ApiKeyMode::from($data['mode']);
            } else {
                $key = MerchantApiKey::whereKey($command->recordId)->lockForUpdate()->firstOrFail();
                if ($key->revoked_at !== null) {
                    throw new ValidationException(ErrorCode::INVALID_API_KEY);
                }
                $key->update(['revoked_at' => now()]);
                if ($command->operation === 'key-revoke') {
                    return ['status' => 'REVOKED'];
                }
                $type = $key->type;
                $mode = $key->mode;
            }
            $issued = app(ApiKeyService::class)->issue($command->merchantId, $command->userId, $type, $mode);

            return ['id' => $issued->id, 'key' => $issued->key];
        });
    }

    /** @return array<string, mixed> */
    private function webhooks(PanelCommand $command): array
    {
        $merchant = Merchant::findOrFail($command->merchantId);
        if ($command->operation === 'webhook-settings') {
            $data = Validator::make($command->data, ['webhook_url' => ['nullable', 'url:https', 'max:2048'], 'allowed_origins' => ['present', 'array', 'max:30'], 'allowed_origins.*' => ['required', 'url:http,https', 'max:2048']])->validate();
            foreach ($data['allowed_origins'] as $origin) {
                $parts = parse_url($origin);
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                    throw new ValidationException(ErrorCode::ORIGIN_NOT_ALLOWED);
                }
            }
            $data['allowed_origins'] = array_map(fn (string $origin): string => rtrim($origin, '/'), $data['allowed_origins']);
            if ($data['webhook_url'] ?? null) {
                app(WebhookUrlPolicy::class)->requestOptions($data['webhook_url']);
            }
            $merchant->update($data);

            return ['status' => 'SAVED'];
        }
        if ($command->operation === 'webhook-rotate') {
            $merchant->update(['webhook_secret' => bin2hex(random_bytes(32))]);
        }
        if (in_array($command->operation, ['webhook-rotate', 'webhook-reveal'], true)) {
            return ['secret' => $merchant->webhook_secret];
        }
        if ($command->operation === 'webhook-resend') {
            $delivery = WebhookDelivery::findOrFail($command->recordId);
            $delivery->update(['is_successful' => false, 'attempt_count' => 0, 'next_retry_at' => now()]);
            app(WebhookService::class)->schedule($delivery);

            return ['status' => 'QUEUED'];
        }
        if (! $merchant->webhook_url || ! $merchant->webhook_secret) {
            throw new ValidationException(ErrorCode::WEBHOOK_URL_NOT_ALLOWED);
        }
        $payment = PaymentSession::create(['merchant_id' => $merchant->id, 'order_id' => 'WEBHOOK-TEST-'.bin2hex(random_bytes(8)), 'amount_paise' => 100, 'currency' => 'INR', 'method' => 'UPI', 'status' => 'SUCCESS', 'completed_at' => now(), 'is_test' => true, 'metadata' => ['webhookTest' => true]]);
        $delivery = app(WebhookService::class)->recordTerminal($payment->id);

        return ['status' => 'QUEUED', 'deliveryId' => $delivery?->id];
    }

    /** @return array<string, mixed> */
    private function testMode(PanelCommand $command): array
    {
        $enabled = $command->operation === 'test-enable';
        MerchantTestMode::updateOrCreate(['merchant_id' => $command->merchantId], ['enabled_at' => $enabled ? now()->startOfSecond() : null, 'expires_at' => $enabled ? now()->addMinutes(30) : null, 'enabled_by' => $command->userId]);

        return ['status' => $enabled ? 'ENABLED' : 'DISABLED'];
    }

    /** @return array<string, mixed> */
    private function simulate(PanelCommand $command): array
    {
        $data = Validator::make($command->data, ['source' => ['required', 'in:SMS,GMAIL'], 'sender' => ['required', 'string', 'max:255'], 'text' => ['required', 'string', 'max:65536'], 'received_at' => ['required', 'date']])->validate();
        if (! MerchantTestMode::where('enabled_at', '<=', now()->format('Y-m-d H:i:s.v'))->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->exists()) {
            throw new ValidationException(ErrorCode::TEST_MODE_INACTIVE);
        }
        $payment = PaymentSession::whereKey($command->recordId)->where('is_test', true)->firstOrFail();
        if ($payment->status->isTerminal() || $payment->receiving_account_id === null) {
            throw new ValidationException(ErrorCode::SESSION_NOT_ACTIVE);
        }

        return app(VerificationPipeline::class)->process(new VerificationData($data['source'], $data['text'], $payment->receiving_account_id, $data['sender'], CarbonImmutable::parse($data['received_at']), $payment->id, isTest: true, testModeActive: true, suppressBan: true, isSimulated: true))->data;
    }
}
