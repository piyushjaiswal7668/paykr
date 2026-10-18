<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observations\Services\AmountExtractor;
use App\Domain\Observers\Data\ObserverRequest;
use App\Domain\Observers\Data\ObserverResult;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\MerchantTestMode;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;

final class ObserverIntake
{
    public function __construct(private VerificationPipeline $pipeline) {}

    public function process(ObserverRequest $data, string $source, string $deviceInfo = ''): ObserverResult
    {
        if (AmountExtractor::extract($data->text) === null) {
            return new ObserverResult(['success' => true, 'status' => 'PROCESSED_NON_BANK']);
        }
        $account = $data->accountId === null ? PaymentReceivingAccount::where('is_active', true)->first() : PaymentReceivingAccount::find($data->accountId);
        if ($account === null) {
            throw new ValidationException(ErrorCode::NO_ACTIVE_RECEIVING_ACCOUNT);
        }
        if ($data->paymentId !== null && ! PaymentSession::whereKey($data->paymentId)->where('receiving_account_id', $account->id)->exists()) {
            throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
        }
        $testMode = $data->isTest && MerchantTestMode::where('enabled_at', '<=', now()->format('Y-m-d H:i:s.v'))->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->exists();
        $result = $this->pipeline->process(new VerificationData($source, $data->text, $account->id, sender: $data->sender, receivedAt: $data->observedAt, paymentSessionId: $data->paymentId, isTest: $data->isTest, testModeActive: $testMode, deviceInfo: $deviceInfo, messageId: $data->messageId));

        return new ObserverResult(array_intersect_key($result->data, array_flip(['success', 'status', 'reason', 'failingStage', 'failingCondition', 'messageId', 'observationId', 'session'])));
    }
}
