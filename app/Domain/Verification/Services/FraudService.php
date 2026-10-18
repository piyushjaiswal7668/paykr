<?php

declare(strict_types=1);

namespace App\Domain\Verification\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\BanService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Events\PaymentFraudBlocked;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;

final class FraudService
{
    public function __construct(private BanService $bans) {}

    /** Called only once all six fraud conditions have been established by the pipeline.
     * @param  array<string, mixed>  $parser
     * @param  array<string, mixed>  $verification
     */
    public function record(VerificationData $input, Customer $customer, PaymentSession $payment, string $sender, string $source, array $parser, array $verification, bool $suppressBan): void
    {
        DB::transaction(function () use ($input, $customer, $payment, $sender, $source, $parser, $verification, $suppressBan): void {
            SecurityEvent::create([
                'merchant_id' => $payment->merchant_id, 'customer_id' => $customer->id, 'payment_session_id' => $payment->id,
                'event_type' => 'FRAUDULENT_PAYMENT_INJECTION', 'source' => $source, 'sender' => $sender, 'device_info' => $input->deviceInfo,
                'normalized_message' => $input->rawText, 'raw_message' => $input->rawText, 'fraud_reason' => 'FRAUDULENT_PAYMENT_INJECTION',
                'extracted_amount' => $parser['details']['amount'] ?? null, 'extracted_reference' => $parser['details']['reference'] ?? null,
                'parser_results' => $parser, 'verification_results' => $verification,
                'metadata' => ['orderId' => $payment->order_id, 'testModeActive' => $input->testModeActive, 'autoBanApplied' => ! $suppressBan, 'banSuppressed' => $suppressBan],
            ]);
            AuditLog::create(['merchant_id' => $payment->merchant_id, 'entity_type' => 'CUSTOMER', 'entity_id' => $customer->id,
                'action' => $suppressBan ? 'TEST_MODE_FRAUD_FLAGGED_BAN_SUPPRESSED' : 'CUSTOMER_AUTOMATICALLY_BANNED', 'actor_type' => 'SYSTEM',
                'details' => ['paymentId' => $payment->id, 'reason' => 'FRAUDULENT_PAYMENT_INJECTION']]);
            if ($suppressBan) {
                return;
            }
            $customer->update(['status' => CustomerStatus::BANNED, 'ban_reason' => 'FRAUDULENT_PAYMENT_INJECTION', 'banned_at' => now(), 'last_device' => $input->deviceInfo]);
            $this->bans->expireLiveSessions($customer->id, new Actor(ActorType::SYSTEM));
            $event = new PaymentFraudBlocked($payment->merchant_id, $payment->id, $customer->id);
            DB::afterCommit(static fn () => event($event));
        });
    }
}
