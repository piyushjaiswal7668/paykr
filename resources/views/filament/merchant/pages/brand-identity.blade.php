<x-filament-panels::page>
<div class="grid gap-8 lg:grid-cols-3">
<form wire:submit="save" class="space-y-6 lg:col-span-2">{{ $this->form }}<x-filament::button type="submit">Save brand identity</x-filament::button></form>
<div class="space-y-4"><h2 class="font-semibold">Live checkout preview</h2>
<div class="flex flex-wrap gap-2">@foreach(['Sapphire Blue'=>'#2563eb','Emerald Green'=>'#059669','Violet Purple'=>'#7c3aed','Rose Crimson'=>'#e11d48','Amber Gold'=>'#d97706','Obsidian Dark'=>'#18181b'] as $name=>$color)<x-filament::button color="gray" size="xs" wire:click="$set('data.primary_color', '{{ $color }}')">{{ $name }}</x-filament::button>@endforeach</div>
<div class="rounded-3xl border-8 border-gray-800 bg-white text-gray-900 overflow-hidden" style="max-width:340px">
@php($color = preg_match('/^#[a-fA-F0-9]{6}$/D', $data['primary_color'] ?? '') ? $data['primary_color'] : '#2563eb')
<div class="p-6 text-white" style="background:{{ ($data['header_theme'] ?? 'gradient') === 'dark' ? '#18181b' : (($data['header_theme'] ?? 'gradient') === 'gradient' ? 'linear-gradient(135deg, '.$color.', #172554)' : $color) }}">@if(preg_match('#^https?://#i', $data['logo_url'] ?? ''))<img class="h-12 max-w-full object-contain mb-3" src="{{ $data['logo_url'] }}" alt="Store logo">@endif<strong>{{ $data['business_name'] ?? 'Your store' }}</strong><p>{{ $data['app_name'] ?? 'Paykr' }}</p>@unless($data['hide_trust_badge'] ?? false)<p>{{ $data['trust_badge_text'] ?? 'Verified merchant' }}</p>@endunless</div>
<div class="space-y-6 p-6">@unless($data['hide_subbanner'] ?? false)<p>{{ $data['subbanner_text'] ?? 'Secure checkout' }}</p>@endunless<p>Sample order #ORD-78921</p><strong class="text-2xl">₹1,500.00</strong><div class="border p-8 text-center">Payment QR preview</div><p>{{ $data['checkout_notice'] ?? '' }}</p><p class="text-sm">{{ $data['footer_guarantee'] ?? 'Secure payments' }}</p>@unless($data['hide_powered_by'] ?? false)<p class="text-xs">Powered by {{ $data['app_name'] ?? 'Paykr' }}</p>@endunless</div>
</div></div></div>
</x-filament-panels::page>
