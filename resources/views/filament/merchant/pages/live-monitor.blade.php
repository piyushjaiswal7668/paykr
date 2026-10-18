<x-filament-panels::page>
<div wire:poll.5s class="space-y-6">
<p>Your business, at a glance. Payments, receiving accounts, and activity.</p>
<div class="grid gap-4 md:grid-cols-3">@foreach($this->stats() as $label => $value)<x-filament::section><span class="text-sm">{{ $label }}</span><p class="text-2xl font-semibold">{{ $value }}</p></x-filament::section>@endforeach</div>
<x-filament::section heading="Current receiving slots">
@forelse(\App\Models\PaymentSession::whereIn('status', ['ACTIVE','PROCESSING'])->where('method', 'UPI')->get() as $payment)
<div wire:key="slot-{{ $payment->id }}" class="py-3"><strong>{{ $payment->order_id }} · {{ $payment->status->value }}</strong><p>{{ $payment->receivingAccount?->upi_id }}</p>
@php($deadline = $payment->status->value === 'PROCESSING' ? $payment->processing_expires_at : $payment->expires_at)
<span x-data="{ remaining: {{ max(0, (int) now()->diffInSeconds($deadline ?? now(), false)) }} }" x-init="setInterval(() => remaining = Math.max(0, remaining - 1), 1000)" x-text="remaining + ' seconds remaining'"></span></div>
@empty<p>No customer actively checking out.</p>@endforelse
</x-filament::section>
<x-filament::section heading="Waiting queue">@forelse(\App\Models\PaymentSession::where('status','QUEUED')->orderBy('queue_position')->get() as $payment)<p>{{ $payment->queue_position }}. {{ $payment->order_id }} — ₹{{ number_format($payment->amount_paise/100,2) }}</p>@empty<p>No payments waiting.</p>@endforelse</x-filament::section>
</div>
</x-filament-panels::page>
