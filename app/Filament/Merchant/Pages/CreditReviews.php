<?php

declare(strict_types=1);

namespace App\Filament\Merchant\Pages;

use App\Domain\Verification\Actions\ApproveHeldCredit;
use App\Filament\Concerns\NotifiesPaykrErrors;
use App\Models\Merchant;
use App\Models\PaymentObservation;
use App\Models\PaymentSession;
use App\Models\User;
use App\Support\UserTimezone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\On;

final class CreditReviews extends Page implements HasTable
{
    #[On('paykr-live-update')]
    public function refreshCredits(): void
    {
        $this->resetTable();
    }

    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.merchant.pages.credit-reviews';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Merchant
            && $user->merchants()->whereKey($tenant->id)->where('status', 'ACTIVE')->wherePivotIn('role', ['owner', 'manager'])->exists();
    }

    public function table(Table $table): Table
    {
        return $table->poll('5s')->query(PaymentObservation::query()->where('attribution_status', 'LATE_UNMATCHED')->whereNull('raw_payload->reviewResolution'))
            ->heading('Credits awaiting review')->defaultSort('ingested_at', 'desc')
            ->columns([
                TextColumn::make('amount_paise')->label('Amount')->formatStateUsing(fn ($state): string => '₹'.number_format(((int) $state) / 100, 2)),
                TextColumn::make('receivingAccount.upi_id')->label('Receiving account'),
                TextColumn::make('bank_reference_number')->label('Bank reference')->searchable(),
                TextColumn::make('source')->badge(),
                TextColumn::make('observed_at')->label('SMS time')->dateTime(),
                TextColumn::make('raw_payload.reviewReason')->label('Reason')->wrap(),
            ])->recordActions([
                Action::make('approve')->label('Review and approve')->modalHeading('Confirm bank credit and order')
                    ->modalDescription('Check the bank transaction independently. Approving a live order marks it paid. A closed order can be reopened as paid only with the additional confirmation below. No refund is sent.')
                    ->schema([
                        Textarea::make('evidence')->label('Received evidence')->default(fn (PaymentObservation $record): string => (string) ($record->raw_payload['text'] ?? ''))->disabled()->dehydrated(false),
                        Select::make('paymentId')->label('Order receiving this credit')->required()->searchable()
                            ->options(fn (PaymentObservation $record): array => PaymentSession::where('receiving_account_id', $record->receiving_account_id)
                                ->where('amount_paise', $record->amount_paise)->where('method', 'UPI')->whereIn('status', ['ACTIVE', 'PROCESSING', 'EXPIRED', 'CANCELLED', 'FAILED'])
                                ->whereNull('attributed_observation_id')->orderByDesc('created_at')->limit(100)->get()
                                ->mapWithKeys(fn (PaymentSession $payment): array => [$payment->id => $payment->order_id.' — '.$payment->status->value.' — '.UserTimezone::format($payment->created_at)])->all()),
                        TextInput::make('reference')->label('Re-enter the 12-digit bank reference')->required()->regex('/^[0-9]{12}$/D'),
                        Textarea::make('note')->label('Review notes')->helperText('Record how you confirmed the bank credit belongs to this order.')->required()->minLength(10)->maxLength(1000),
                        Checkbox::make('reopenConfirmed')->label('If this order has ended, I explicitly authorize reopening it as paid.'),
                        Checkbox::make('confirmed')->label('I checked the bank credit and its association with this order.')->accepted()->required(),
                    ])->modalSubmitActionLabel('Approve credit')
                    ->action(function (PaymentObservation $record, array $data): void {
                        abort_unless(static::canAccess(), 403);
                        $outcome = NotifiesPaykrErrors::run(fn () => app(ApproveHeldCredit::class)->handle(
                            (string) Filament::getTenant()?->getKey(), (string) Filament::auth()->id(), $record->id,
                            $data['paymentId'], $data['reference'], $data['note'], (bool) ($data['reopenConfirmed'] ?? false)));
                        if ($outcome !== null) {
                            Notification::make()->success()->title('Credit approved; order marked paid')->send();
                        }
                    }),
            ])->emptyStateHeading('No credits awaiting review');
    }
}
