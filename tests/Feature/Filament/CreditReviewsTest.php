<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Payments\Services\SlotQueueService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Verification\Actions\ApproveHeldCredit;
use App\Filament\Merchant\Pages\CreditReviews;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\WebhookDelivery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(function () {
    Bus::fake();
    $this->merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.example/hook']);
    $this->user = User::factory()->create();
    $this->merchant->users()->attach($this->user, ['role' => 'owner']);
    app(TenantContext::class)->run($this->merchant->id, function (): void {
        $this->account = PaymentReceivingAccount::factory()->active()->create(['is_merchant_enabled' => true]);
        $this->payment = PaymentSession::factory()->forAccount($this->account)->create(['amount_paise' => 100]);
        app(SlotQueueService::class)->enqueueOrActivate($this->payment->id, $this->account->id, new Actor(ActorType::SYSTEM));
        $this->credit = PaymentObservation::factory()->create(['receiving_account_id' => $this->account->id,
            'amount_paise' => 100, 'bank_reference_number' => '123456789012', 'attribution_status' => 'LATE_UNMATCHED',
            'raw_payload' => ['text' => 'INR 1 credited UTR 123456789012', 'reviewReason' => 'AMBIGUOUS_LATE_CREDIT']]);
    });
});

it('lets an owner approve through the dashboard with evidence confirmation and one audit', function () {
    $this->actingAs($this->user)->get('/merchant/'.$this->merchant->slug.'/credit-reviews')->assertOk()->assertSee('Credits awaiting review');
    Filament::setCurrentPanel(Filament::getPanel('merchant'));
    Filament::setTenant($this->merchant);
    app(TenantContext::class)->run($this->merchant->id, function (): void {
        Livewire::test(CreditReviews::class)->assertCanSeeTableRecords([$this->credit])
            ->callTableAction('approve', $this->credit, ['paymentId' => $this->payment->id, 'reference' => '123456789012', 'note' => 'Checked bank statement and order receipt.', 'confirmed' => true, 'reopenConfirmed' => false])
            ->assertHasNoTableActionErrors();
        expect($this->payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        expect($this->credit->refresh()->raw_payload['reviewResolution']['approvedBy'])->toBe($this->user->id);
        expect(app(ApproveHeldCredit::class)->handle($this->merchant->id, $this->user->id, $this->credit->id, $this->payment->id, '123456789012', 'Checked bank statement and order receipt.'))->toBe('APPROVED_PAID');
        expect(AuditLog::where('action', 'HELD_CREDIT_APPROVED')->count())->toBe(1);
        expect(WebhookDelivery::where('event_type', 'SUCCESS')->count())->toBe(1);
    });
});

it('requires explicit reopening and preserves the newer account slot', function (PaymentStatus $closedStatus) {
    app(TenantContext::class)->run($this->merchant->id, function () use ($closedStatus): void {
        if ($closedStatus === PaymentStatus::FAILED) {
            app(PaymentTransitionService::class)->transition($this->payment->id, PaymentStatus::PROCESSING, new Actor(ActorType::SYSTEM));
        }
        app(PaymentTransitionService::class)->transition($this->payment->id, $closedStatus, new Actor(ActorType::SYSTEM));
        $next = PaymentSession::factory()->forAccount($this->account)->create();
        app(SlotQueueService::class)->enqueueOrActivate($next->id, $this->account->id, new Actor(ActorType::SYSTEM));
        $args = [$this->merchant->id, $this->user->id, $this->credit->id, $this->payment->id, '123456789012', 'Checked transaction independently.'];
        expect(fn () => app(ApproveHeldCredit::class)->handle(...$args))->toThrow(ValidationException::class);
        expect($this->payment->refresh()->status)->toBe($closedStatus);
        app(ApproveHeldCredit::class)->handle(...[...$args, true]);
        expect($this->payment->refresh()->status)->toBe(PaymentStatus::SUCCESS);
        expect($this->account->refresh()->current_active_session_id)->toBe($next->id);
        expect($next->refresh()->status)->toBe(PaymentStatus::ACTIVE);
        expect(WebhookDelivery::where('payment_id', $this->payment->id)->count())->toBe(2);
        expect($this->credit->refresh()->raw_payload['reviewResolution']['previousStatus'])->toBe($closedStatus->value);
    });
})->with([PaymentStatus::EXPIRED, PaymentStatus::CANCELLED, PaymentStatus::FAILED]);

it('denies cashier developer and unrelated users at both page and action boundaries', function (string $role) {
    $this->merchant->users()->updateExistingPivot($this->user->id, ['role' => $role]);
    $this->actingAs($this->user)->get('/merchant/'.$this->merchant->slug.'/credit-reviews')->assertForbidden();
    expect(fn () => app(ApproveHeldCredit::class)->handle($this->merchant->id, $this->user->id, $this->credit->id, $this->payment->id, '123456789012', 'Checked transaction independently.'))->toThrow(ValidationException::class);
})->with(['cashier', 'developer']);

it('rejects a wrong reference, cross-tenant order and reuse of an already assigned reference', function () {
    $other = Merchant::factory()->create();
    $otherPayment = app(TenantContext::class)->run($other->id, fn () => PaymentSession::factory()->create());
    $base = [$this->merchant->id, $this->user->id, $this->credit->id];
    expect(fn () => app(ApproveHeldCredit::class)->handle(...[...$base, $this->payment->id, '999999999999', 'Checked transaction independently.']))->toThrow(ValidationException::class);
    expect(fn () => app(ApproveHeldCredit::class)->handle(...[...$base, $otherPayment->id, '123456789012', 'Checked transaction independently.']))->toThrow(ValidationException::class);
    app(TenantContext::class)->run($this->merchant->id, function () use ($base): void {
        PaymentObservation::factory()->create(['receiving_account_id' => $this->account->id, 'bank_reference_number' => '123456789012', 'attribution_status' => 'ATTRIBUTED', 'attributed_payment_id' => $this->payment->id]);
        expect(fn () => app(ApproveHeldCredit::class)->handle(...[...$base, $this->payment->id, '123456789012', 'Checked transaction independently.']))->toThrow(ValidationException::class);
        expect($this->payment->refresh()->status)->toBe(PaymentStatus::ACTIVE);
    });
});

it('does not allow an ordinary transition or unapproved reviewed transition to reopen a closed payment', function () {
    app(TenantContext::class)->run($this->merchant->id, function (): void {
        $transitions = app(PaymentTransitionService::class);
        $transitions->transition($this->payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM));
        expect(fn () => $transitions->transition($this->payment->id, PaymentStatus::SUCCESS, new Actor(ActorType::SYSTEM)))->toThrow(ValidationException::class);
        expect(fn () => $transitions->settleReviewedCredit($this->payment->id, new Actor(ActorType::MERCHANT_USER, $this->user->id, $this->merchant->id)))->toThrow(ValidationException::class);
        expect($this->payment->refresh()->status)->toBe(PaymentStatus::CANCELLED);
    });
});
