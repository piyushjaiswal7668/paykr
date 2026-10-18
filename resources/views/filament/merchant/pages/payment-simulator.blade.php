<x-filament-panels::page><div wire:poll.5s class="space-y-6">
@php($mode = \App\Models\MerchantTestMode::first())
<x-filament::section heading="Developer test mode"><p>{{ $mode?->expires_at?->isFuture() ? 'Enabled — '.max(0,(int) now()->diffInSeconds($mode->expires_at,false)).' seconds remaining' : 'Disabled' }}</p><p>Only test payments belonging to this store can receive simulated observations. Create test sessions with a test API key.</p></x-filament::section>
@if($result)<x-filament::section heading="Stage-by-stage result"><pre class="whitespace-pre-wrap">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre></x-filament::section>@endif
<x-filament::section heading="Simulation history">@forelse(\App\Models\AuditLog::where('action','SIMULATE')->latest()->limit(30)->get() as $entry)<details><summary>{{ $entry->created_at }} — {{ $entry->entity_id }}</summary><pre class="whitespace-pre-wrap">{{ json_encode($entry->details['result'] ?? [], JSON_PRETTY_PRINT) }}</pre></details>@empty<p>No simulations yet.</p>@endforelse</x-filament::section>
</div></x-filament-panels::page>
