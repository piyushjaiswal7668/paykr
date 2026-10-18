<x-filament-panels::page><div wire:poll.15s>
@php($connection = \App\Models\MerchantGmailConnection::first())
<x-filament::section heading="Bank email connection"><p>Status: {{ $connection?->status->value ?? 'DISCONNECTED' }}</p><p>{{ $connection?->connected_email }}</p><p>Last successful fetch: {{ $connection?->last_successful_fetch ?? 'Never' }}</p><p>OAuth credentials are stored encrypted and never displayed here.</p></x-filament::section>
<p>WhatsApp OTP is managed by the platform. See Observers for sender availability.</p>
@if($result)<p>{{ $result['status'] ?? '' }}</p>@endif
</div></x-filament-panels::page>
