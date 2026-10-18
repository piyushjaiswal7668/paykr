<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Data\ObserverRequest;
use App\Domain\Observers\Data\ObserverResult;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;

final class InternalObserver
{
    public function __construct(private TenantContext $tenants, private ObserverSignatures $signatures, private ObserverIntake $intake) {}

    public function execute(ObserverRequest $data): ObserverResult
    {
        if ($data->merchantId === null || ! Merchant::whereKey($data->merchantId)->where('status', 'ACTIVE')->exists()) {
            throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);
        }

        return $this->tenants->run($data->merchantId, function () use ($data): ObserverResult {
            if ($data->operation === 'active-window') {
                return new ObserverResult(['merchantId' => $data->merchantId, 'sessions' => PaymentSession::where('method', 'UPI')->whereIn('status', ['ACTIVE', 'PROCESSING'])->get()->map(fn (PaymentSession $p) => ['paymentSessionId' => $p->id, 'receivingAccountId' => $p->receiving_account_id, 'amountPaise' => $p->amount_paise, 'status' => $p->status->value, 'expiresAt' => $p->status->value === 'PROCESSING' ? $p->processing_expires_at : $p->expires_at])->all()]);
            }
            $hash = $this->signatures->verify('internal:'.$data->merchantId, config('paykr.internal_token'), $data->rawBody, $data->timestamp, $data->signature);

            return DB::transaction(function () use ($data, $hash): ObserverResult {
                $this->signatures->consume($hash);

                return $this->intake->process($data, 'GOOGLE_MESSAGES', 'Google Messages sidecar');
            }, 5);
        });
    }
}
