@if(\Filament\Facades\Filament::getTenant())
<div class="flex gap-3 text-xs" aria-label="Store status"><span>{{ \Filament\Facades\Filament::getTenant()->status->value }}</span><span>VPA: {{ \App\Models\PaymentReceivingAccount::where('is_active',true)->value('upi_id') ?? 'Not configured' }}</span><span data-merchant-connection>Connecting…</span></div>
@endif
