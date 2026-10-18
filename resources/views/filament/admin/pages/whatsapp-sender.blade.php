<x-filament-panels::page><div wire:poll.10s class="space-y-4">
@php($state = $this->status())
<p>Status: {{ $state['status'] ?? 'Unknown' }} · {{ $state['phoneNumber'] ?? '' }}</p>
@if(isset($state['qrCode']) && str_starts_with($state['qrCode'],'data:image/png;base64,'))<img width="260" height="260" alt="WhatsApp sender pairing QR" src="{{ $state['qrCode'] }}">@endif
@if(!empty($state['pairingCode']))<p>On the sender phone: WhatsApp → Linked devices → Link a device → Link with phone number.</p><strong>{{ $state['pairingCode'] }}</strong>@endif
@if($result)<p>{{ $result['status'] ?? '' }} {{ ($result['delivered'] ?? false) ? 'Message delivered' : '' }}</p>@endif
</div></x-filament-panels::page>
