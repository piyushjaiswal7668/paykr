<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Observations\Data\ObservationData;
use App\Domain\Observations\Enums\AttributionStatus;
use App\Domain\Observations\Enums\ObserverSource;
use App\Domain\Observations\Services\ObservationNormalizer;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Jobs\FinalizeLevel1Payment;
use App\Domain\Verification\Services\AttributionEngine;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantTestMode;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SecurityEvent;
use App\Models\SupportTicket;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    $this->travelTo(now()->startOfSecond());
    Bus::fake([FinalizeLevel1Payment::class, DeliverWebhook::class]);
    config(['paykr.payments.grace_period_ms' => 0]);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function withinVerificationMerchant(Closure $test): void
{
    $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook', 'webhook_secret' => 'test-secret']);
    app(TenantContext::class)->run($merchant->id, function () use ($merchant, $test) {
        $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $customer = Customer::factory()->create(['mobile_number' => '9876543210', 'email' => 'customer@example.test']);
        $payment = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000, 'customer_id' => $customer->id]);
        app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));
        $test($account, $payment->refresh(), $customer, $merchant, app(VerificationPipeline::class), app(AttributionEngine::class));
    });
}

function bankInput(PaymentReceivingAccount $account, ?PaymentSession $payment = null, string $source = 'SMS', string $text = 'Rs. 200 credited UTR: 123456789012', ?string $sender = null): VerificationData
{
    return new VerificationData($source, $text, $account->id, sender: $sender, receivedAt: now()->toImmutable(), paymentSessionId: $payment?->id);
}

it('attributes matching observation to active processing session', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::PROCESSING, new Actor(ActorType::SYSTEM));
        $result = $pipeline->process(bankInput($account, $payment));
        expect($result->isVerified())->toBeTrue();
        expect($result->data['status'])->toBe('VERIFIED')->and($result->data['amountPaise'])->toBe('20000');
        expect($result->data['stages']['stage6']['status'])->toBe('ATTRIBUTED');
        expect($result->data['session']['status'])->toBe('SUCCESS');
        expect(array_keys($result->data['stages']))->toBe(['stage1', 'stage2', 'stage3', 'stage4', 'stage5', 'stage6']);
        expect($result->data['raw'])->toBe('Rs. 200 credited UTR: 123456789012');
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        expect($payment->verification_level)->toBe(1);
        expect(WebhookDelivery::count())->toBe(1);
        expect(PaymentObservation::sole()->attribution_status)->toBe(AttributionStatus::ATTRIBUTED);
    });
});

it('deduplicates multi-channel observations (SMS + Gmail)', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        expect($pipeline->process(bankInput($account, $payment))->isVerified())->toBeTrue();
        expect($pipeline->process(bankInput($account, $payment, 'GMAIL'))->isVerified())->toBeTrue();
        expect($payment->refresh()->verification_level)->toBe(2);
        expect(WebhookDelivery::count())->toBe(1);
        $duplicate = $pipeline->process(bankInput($account, $payment));
        expect($duplicate->data['reason'])->toBe('DUPLICATE_OBSERVATION');
        expect(PaymentObservation::count())->toBe(2);
    });
});

it('never attributes rejected observations or searches other accounts', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $cases = [
            ['SMS', 'Rs 200 credited UTR: 123456789012', '9999999999', 'PERSONAL_SMS_REJECTED'],
            ['SMS', 'Rs 200 credited UTR: 123456789012', 'not a bank!', 'INVALID_SMS_SENDER'],
            ['GMAIL', 'Rs 200 credited UTR: 123456789012', 'attacker@example.com', 'INVALID_EMAIL_DOMAIN'],
            ['UNKNOWN', 'Rs 200 credited UTR: 123456789012', null, 'IRRELEVANT_SOURCE'],
            ['SMS', 'Rs 200 debited UTR: 123456789012', null, 'MISSING_CREDIT_INDICATOR'],
            ['SMS', '200 credited UTR: 123456789012', null, 'MISSING_CURRENCY_INDICATOR'],
            ['SMS', 'Rs 200 credited', null, 'MISSING_12_DIGIT_REFERENCE'],
            ['SMS', 'Rs 300 credited UTR: 123456789012', null, 'AMOUNT_MISMATCH'],
            ['SMS', 'Rs zero credited UTR: 123456789012', null, 'INVALID_OR_MISSING_AMOUNT'],
        ];
        foreach ($cases as [$source,$text,$sender,$reason]) {
            $result = $pipeline->process(bankInput($account, $payment, $source, $text, $sender));
            expect($result->data['status'])->toBe('REJECTED')->and($result->data['reason'])->toBe($reason);
            expect($result->data['stages']['stage6']['status'])->toBe('PENDING');
        }
        $other = PaymentReceivingAccount::factory()->create();
        expect($pipeline->process(bankInput($other, $payment))->data['reason'])->toBe('NO_ACTIVE_SESSION');
        expect($pipeline->process(bankInput($other))->data['status'])->toBe('NEEDS_REVIEW');
        expect(PaymentObservation::sole()->receiving_account_id)->toBe($other->id);
        expect(PaymentObservation::sole()->attributed_payment_id)->toBeNull();
        expect(PaymentObservation::count())->toBe(1)->and(WebhookDelivery::count())->toBe(0);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('rejects observation timestamps outside the exact payment verification window', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        foreach ([-2 => 'BEFORE_SESSION_CREATION', 301 => 'SESSION_EXPIRED'] as $seconds => $reason) {
            $result = $pipeline->process(new VerificationData('SMS', 'Rs 200 credited UTR 123456789012', $account->id, receivedAt: now()->toImmutable()->addSeconds($seconds), paymentSessionId: $payment->id));
            expect($result->data['reason'])->toBe($reason);
        }
        expect(PaymentObservation::count())->toBe(1);
        expect(PaymentObservation::sole()->attribution_status)->toBe(AttributionStatus::LATE_UNMATCHED);
    });
});

it('persists Level 1 grace and finalizes only once after its deadline', function () {
    config(['paykr.payments.grace_period_ms' => 5000]);
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $result = $pipeline->process(bankInput($account, $payment));
        expect($result->data['stages']['stage6']['outcomeStatus'])->toBe('VERIFYING');
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payment->verification_grace_expires_at->equalTo(now()->addSeconds(5)))->toBeTrue();
        Bus::assertDispatched(FinalizeLevel1Payment::class);
        $job = new FinalizeLevel1Payment($merchant->id, $payment->id);
        $job->handle(app(TenantContext::class), $engine);
        expect(WebhookDelivery::count())->toBe(0);
        $this->travel(5)->seconds();
        $this->artisan('paykr:verification:recover')->expectsOutput('Verification deadlines queued: 1')->assertSuccessful();
        $job->handle(app(TenantContext::class), $engine);
        $job->handle(app(TenantContext::class), $engine);
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        expect($payment->verification_grace_expires_at)->toBeNull();
        expect(WebhookDelivery::count())->toBe(1);
    });
});

it('finalizes dual verification during grace and makes its old delayed job harmless', function () {
    config(['paykr.payments.grace_period_ms' => 5000]);
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $pipeline->process(bankInput($account, $payment));
        $pipeline->process(bankInput($account, $payment, 'GMAIL'));
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS)->and($payment->verification_level)->toBe(2);
        $this->travel(6)->seconds();
        $engine->finalizeLevel1($payment->id);
        expect(WebhookDelivery::count())->toBe(1);
        expect($payment->refresh()->verification_grace_expires_at)->toBeNull();
    });
});

it('does not resurrect a payment cancelled during grace and creates one reconciliation ticket', function () {
    config(['paykr.payments.grace_period_ms' => 5000]);
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $pipeline->process(bankInput($account, $payment));
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
        $this->travel(5)->seconds();
        $engine->finalizeLevel1($payment->id);
        $engine->finalizeLevel1($payment->id);
        expect($payment->refresh()->status)->toBe(PaymentStatus::CANCELLED);
        expect(PaymentObservation::sole()->attribution_status)->toBe(AttributionStatus::LATE_UNMATCHED);
        expect(SupportTicket::count())->toBe(1);
        expect(SupportTicket::sole()->messages()->sole()->message)->toContain('NOT marked paid');
        expect(WebhookDelivery::sole()->event_type)->toBe(PaymentStatus::CANCELLED);
    });
});

it('CRITICAL INVARIANT: Late observation does not attach to a new queued customer', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $observedAt = now()->toImmutable();
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::EXPIRED, new Actor(ActorType::SYSTEM));
        $this->travel(60)->seconds();
        $next = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000]);
        app(SlotQueueService::class)->enqueueOrActivate($next->id, $account->id, new Actor(ActorType::SYSTEM));
        $input = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GMAIL, amountPaise: 20000, observedAt: $observedAt, bankReferenceNumber: '123456789012'));
        $result = $engine->process($input);
        expect($result->lateMatch)->toBeTrue()->and($result->paymentSessionId)->toBe($payment->id);
        expect($next->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect($payment->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect(SupportTicket::count())->toBe(1);
    });
});

it('never attributes an observation to another merchants session even with an explicit target', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $otherMerchant = Merchant::factory()->create();
        $otherPayment = app(TenantContext::class)->run($otherMerchant->id, function () {
            $account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
            $payment = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000]);
            app(SlotQueueService::class)->enqueueOrActivate($payment->id, $account->id, new Actor(ActorType::SYSTEM));

            return $payment;
        });
        $input = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GMAIL, ['targetSessionId' => $otherPayment->id], amountPaise: 20000, bankReferenceNumber: '123456789012'));
        expect($engine->process($input)->attributed)->toBeFalse();
        expect($pipeline->process(bankInput($account, $otherPayment))->data['reason'])->toBe('NO_ACTIVE_SESSION');
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        app(TenantContext::class)->run($otherMerchant->id, fn () => expect($otherPayment->refresh()->status)->toBe(PaymentStatus::ACTIVE));
    });
});

it('blocks reused references and limits successful upgrades to sixty seconds', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $pipeline->process(bankInput($account, $payment));
        $this->travel(61)->seconds();
        expect($pipeline->process(bankInput($account, $payment, 'GMAIL'))->data['status'])->toBe('UNRESOLVED');
        expect($payment->refresh()->verification_level)->toBe(1);
        $next = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 20000]);
        app(SlotQueueService::class)->enqueueOrActivate($next->id, $account->id, new Actor(ActorType::SYSTEM));
        expect($pipeline->process(bankInput($account, $next, 'GMAIL'))->data['reason'])->toBe('DUPLICATE_OBSERVATION');
        // Another source has not yet been stored for a different completed reference.
        expect($pipeline->process(bankInput($account, $next, 'SMS'))->data['reason'])->toBe('DUPLICATE_OBSERVATION');
        expect($next->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('automatically bans a customer injection globally and persists every terminal webhook', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $otherMerchant = Merchant::factory()->create(['webhook_url' => 'https://other.example/hook', 'webhook_secret' => 'secret']);
        $otherPayment = app(TenantContext::class)->run($otherMerchant->id, fn () => PaymentSession::factory()->create(['customer_id' => $customer->id]));
        $raw = "  Rs 200 credited UTR: 123456789012\n";
        $result = $pipeline->process(bankInput($account, $payment, 'SMS', $raw, '+91 98765 43210'));
        expect($result->data['reason'])->toBe('FRAUDULENT_PAYMENT_INJECTION');
        expect($customer->refresh()->status)->toBe(CustomerStatus::BANNED);
        expect($payment->refresh()->status)->toBe(PaymentStatus::EXPIRED);
        expect(SecurityEvent::sole()->getRawOriginal('raw_message'))->toBe($raw);
        expect(PaymentObservation::count())->toBe(0);
        expect($account->refresh()->is_slot_busy)->toBeFalse();
        expect(WebhookDelivery::count())->toBe(1);
        app(TenantContext::class)->run($otherMerchant->id, function () use ($otherPayment) {
            expect($otherPayment->refresh()->status)->toBe(PaymentStatus::EXPIRED);
            expect(WebhookDelivery::count())->toBe(1);
        });
        expect(app(TenantContext::class)->merchantId())->toBe($merchant->id);
    });
});

it('only suppresses bans in a persisted active test mode and never pays suspicious observations', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $payment->update(['is_test' => true]);
        $input = new VerificationData('SMS', 'Rs 200 credited UTR: 123456789012', $account->id, sender: '9876543210', paymentSessionId: $payment->id, isTest: true, testModeActive: true, suppressBan: true, isSimulated: true);
        expect($pipeline->process($input)->data['reason'])->toBe('TEST_MODE_INACTIVE');
        MerchantTestMode::factory()->create(['merchant_id' => $merchant->id, 'enabled_at' => now()->subMinute(), 'expires_at' => now()->addMinute()]);
        $result = $pipeline->process($input);
        expect($result->data['status'])->toBe('SUSPICIOUS');
        expect($result->data['stages']['stage6']['status'])->toBe('FLAGGED_SUSPICIOUS');
        expect($customer->refresh()->status)->toBe(CustomerStatus::ACTIVE);
        expect($payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect(PaymentObservation::count())->toBe(0)->and(WebhookDelivery::count())->toBe(0);
        expect(SecurityEvent::sole()->metadata['banSuppressed'])->toBeTrue();
    });
});

it('rejects banned identities and never treats unbound customer senders as bank evidence', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $customer->update(['status' => CustomerStatus::BANNED]);
        expect($pipeline->process(bankInput($account, $payment))->data['reason'])->toBe('BANNED_IDENTITY');
        $customer->update(['status' => CustomerStatus::ACTIVE]);
        $payment->update(['customer_id' => null]);
        $result = $pipeline->process(new VerificationData('SMS', 'Rs 200 credited UTR: 123456789012', $account->id, sender: '9876543210', paymentSessionId: $payment->id, customerId: $customer->id));
        expect($result->data['reason'])->toBe('CUSTOMER_SENDER_NOT_BANK');
        expect(PaymentObservation::count())->toBe(0);
    });
});

it('rolls back attribution grace and webhook creation with an enclosing transaction', function () {
    config(['paykr.payments.grace_period_ms' => 5000]);
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        expect(fn () => DB::transaction(function () use ($account, $payment, $pipeline) {
            $pipeline->process(bankInput($account, $payment));
            throw new RuntimeException('rollback');
        }))->toThrow(RuntimeException::class);
        expect(PaymentObservation::count())->toBe(0);
        expect($payment->refresh()->verification_grace_expires_at)->toBeNull();
        Bus::assertNothingDispatched();
    });
});

it('rejects reference reuse on a different amount', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $pipeline->process(bankInput($account, $payment));
        $next = PaymentSession::factory()->forAccount($account)->create(['amount_paise' => 30000]);
        app(SlotQueueService::class)->enqueueOrActivate($next->id, $account->id, new Actor(ActorType::SYSTEM));
        expect($pipeline->process(bankInput($account, $next, text: 'Rs 300 credited UTR 123456789012'))->data['reason'])->toBe('REFERENCE_ALREADY_USED');
        expect($next->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('applies customer fraud detection for email identities without trusting suppressBan alone', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $result = $pipeline->process(new VerificationData('GMAIL', 'Rs 200 credited UTR 123456789012', $account->id,
            sender: 'Customer <customer@example.test>', paymentSessionId: $payment->id, suppressBan: true));
        expect($result->data['reason'])->toBe('FRAUDULENT_PAYMENT_INJECTION');
        expect($customer->refresh()->status)->toBe(CustomerStatus::BANNED);
    });
});

it('finds unbound sender identities without ever treating their messages as bank evidence', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        $payment->update(['customer_id' => null]);
        foreach (['SMS' => '9876543210', 'GMAIL' => 'customer@example.test'] as $source => $sender) {
            expect($pipeline->process(bankInput($account, $payment, $source, sender: $sender))->data['reason'])->toBe('CUSTOMER_SENDER_NOT_BANK');
        }
        expect($customer->refresh()->status)->toBe(CustomerStatus::ACTIVE);
    });
});

it('uses strict bank domains in production while accepting display-name bank senders', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline) {
        app()->instance('env', 'production');
        try {
            foreach (['TEST', 'alerts@merchant.paykr.local', 'alerts@hdfcbank.net', 'alerts@bank.in.attacker.com'] as $sender) {
                expect($pipeline->process(bankInput($account, $payment, 'GMAIL', sender: $sender))->data['reason'])->toBe('INVALID_EMAIL_DOMAIN');
            }
            expect($pipeline->process(bankInput($account, $payment, 'GMAIL', sender: 'HDFC Bank <alerts@hdfc.bank.in>'))->isVerified())->toBeTrue();
        } finally {
            app()->instance('env', 'testing');
        }
    });
});

it('handles normalized duplicates recent successes and unmatched observations without global fallback', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $one = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GOOGLE_MESSAGES, amountPaise: 20000, bankReferenceNumber: '123456789012'));
        $first = $engine->process($one);
        expect($first->attributed)->toBeTrue();
        expect($engine->process($one)->duplicate)->toBeTrue();
        // No fingerprint match, but a missing UTR can still confirm a recent success within its window.
        $two = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GMAIL, amountPaise: 20000));
        expect($engine->process($two)->verificationLevel)->toBe(2);
        expect(WebhookDelivery::count())->toBe(1);
        $unmatched = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GMAIL, amountPaise: 50000));
        expect($engine->process($unmatched)->outcomeStatus)->toBe('UNATTRIBUTED');
        $bad = ObservationNormalizer::normalize(new ObservationData('missing', ObserverSource::GMAIL, amountPaise: 20000));
        expect(fn () => $engine->process($bad))->toThrow(ValidationException::class);
    });
});

it('routes trusted credits on closed sessions to reconciliation without duplicate tickets', function () {
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
        $input = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GMAIL, ['targetSessionId' => $payment->id], amountPaise: 20000, bankReferenceNumber: '123456789012'));
        expect($engine->process($input)->lateMatch)->toBeTrue();
        $second = ObservationNormalizer::normalize(new ObservationData($account->id, ObserverSource::GOOGLE_MESSAGES, amountPaise: 20000, bankReferenceNumber: '123456789012'));
        expect($engine->process($second)->lateMatch)->toBeTrue();
        expect(SupportTicket::count())->toBe(1);
        expect(WebhookDelivery::count())->toBe(1);
    });
});

it('keeps conflicting references unattributed during grace and recovers an enqueue failure', function () {
    config(['paykr.payments.grace_period_ms' => 5000]);
    withinVerificationMerchant(function ($account, $payment, $customer, $merchant, $pipeline, $engine) {
        $original = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $pipeline->process(bankInput($account, $payment));
        expect($payment->refresh()->verification_grace_expires_at)->not->toBeNull();
        Bus::swap($original);
        $result = $pipeline->process(bankInput($account, $payment, 'GMAIL', 'Rs 200 credited UTR 999999999999'));
        expect($result->data['status'])->toBe('UNRESOLVED');
        expect($payment->refresh()->verification_level)->toBe(1);
        $this->travel(5)->seconds();
        $engine->finalizeLevel1($payment->id);
        expect($payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        expect(WebhookDelivery::count())->toBe(1);
        $engine->finalizeLevel1('missing');
        $unbound = PaymentSession::factory()->create();
        $engine->finalizeLevel1($unbound->id);
    });
});

it('can roll back and reapply the persisted grace deadline migration', function () {
    expect(Schema::hasColumn('payment_sessions', 'verification_grace_expires_at'))->toBeTrue();
    $steps = DB::table('migrations')->where('migration', '>=', '2026_10_18_000003_add_verification_grace_deadline')->count();
    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();
    expect(Schema::hasColumn('payment_sessions', 'verification_grace_expires_at'))->toBeFalse();
    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumn('payment_sessions', 'verification_grace_expires_at'))->toBeTrue();
});
