<x-filament-panels::page>
<div wire:poll.30s="refreshPairing">@if(isset($result['sessionId']))
<x-filament::section heading="Pair your Android phone"><p>Scan in Paykr Observer. This QR is single-use and expires after ten minutes.</p>
<img width="260" height="260" alt="Android pairing QR" src="data:image/svg+xml;base64,{{ base64_encode(app(\App\Domain\Payments\Services\QrCodeService::class)->svg(json_encode($result, JSON_THROW_ON_ERROR), 260)) }}">
<p>Server: {{ $result['serverUrl'] }}</p></x-filament::section>
@elseif($result)<x-filament::section heading="Parser preview — no payment is attributed"><pre class="whitespace-pre-wrap">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre></x-filament::section>@endif</div>
{{ $this->table }}
</x-filament-panels::page>
