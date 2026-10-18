<?php

declare(strict_types=1);

namespace App\Domain\Verification\Services;

use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\Bus;

final class VerificationRecoveryService
{
    public function __construct(private TenantContext $tenants) {}

    public function dispatchDue(): int
    {
        return $this->tenants->runUnscoped(function (): int {
            $payments = PaymentSession::whereNotNull('verification_grace_expires_at')
                ->where('verification_grace_expires_at', '<=', now()->format('Y-m-d H:i:s.v'))
                ->orderBy('verification_grace_expires_at')->limit(100)->get();
            foreach ($payments as $payment) {
                Bus::dispatch(new FinalizeLevel1Payment($payment->merchant_id, $payment->id));
            }

            return $payments->count();
        });
    }
}
