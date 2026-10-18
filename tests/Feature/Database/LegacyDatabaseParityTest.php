<?php

declare(strict_types=1);

use App\Domain\Observations\Enums\ObserverSource;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Support\Enums\SenderType;
use App\Domain\Support\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('migrations seed default merchant and active UPI receiving account', function () {
    config(['paykr.admin.email' => 'seed@example.test', 'paykr.admin.password' => 'unique-test-password']);
    $this->seed();
    $merchant = Merchant::firstOrFail();
    expect($merchant->business_name)->toBe('Paykr Demo Store')->and($merchant->slug)->toBe('demo-store');
    app(TenantContext::class)->run($merchant->id, function () {
        $account = PaymentReceivingAccount::where('is_active', true)->firstOrFail();
        expect($account->id)->toBe('00000000-0000-0000-0000-000000000002');
        expect($account->upi_id)->toBe('merchant@upi')->and($account->is_active)->toBeTrue()->and($account->is_slot_busy)->toBeFalse();
    });
    expect(Customer::count())->toBe(0);
});

it('records observations and retrieves by fingerprint for deduplication', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $observation = PaymentObservation::factory()->create(['source' => ObserverSource::GOOGLE_MESSAGES, 'amount_paise' => 20000, 'bank_reference_number' => 'UTR998877', 'fingerprint_hash' => 'hash_abc_123', 'raw_payload' => ['text' => 'Received Rs 200 via UPI']]);
        expect($observation->bank_reference_number)->toBe('UTR998877');
        expect(PaymentObservation::where('fingerprint_hash', 'hash_abc_123')->firstOrFail()->id)->toBe($observation->id);
    });
});

it('creates tickets, links payments, and records bidirectional chat', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        $customer = Customer::factory()->create();
        $payment = PaymentSession::factory()->create(['customer_id' => $customer->id]);
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->id, 'payment_id' => $payment->id]);
        expect($ticket->status)->toBe(TicketStatus::OPEN)->and($ticket->payment->id)->toBe($payment->id);
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'sender_type' => SenderType::CUSTOMER, 'sender_id' => $customer->id, 'created_at' => now()->subSecond()]);
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'sender_type' => SenderType::ADMIN, 'sender_id' => User::factory()->create()->id]);
        expect($ticket->messages()->orderBy('created_at')->pluck('sender_type')->all())->toBe([SenderType::CUSTOMER, SenderType::ADMIN]);
    });
});

it('records and queries audit trail', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        AuditLog::factory()->create(['entity_type' => 'PAYMENT_SESSION', 'entity_id' => 'pay_123', 'action' => 'SLOT_ACQUIRED', 'details' => ['queuePosition' => 0]]);
        $logs = AuditLog::where('entity_type', 'PAYMENT_SESSION')->where('entity_id', 'pay_123')->get();
        expect($logs)->toHaveCount(1)->and($logs[0]->action)->toBe('SLOT_ACQUIRED')->and($logs[0]->details['queuePosition'])->toBe(0);
    });
});

it('does not seed demo merchants or customers in production', function () {
    $this->app->instance('env', 'production');
    config(['app.url' => 'https://pay.example.com', 'app.debug' => false, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
    config(['paykr.admin.email' => 'admin@example.test', 'paykr.admin.password' => 'unique-production-password', 'paykr.internal_token' => bin2hex(random_bytes(32)), 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    expect(User::count())->toBe(1)->and(Merchant::count())->toBe(0)->and(Customer::count())->toBe(0);
});
