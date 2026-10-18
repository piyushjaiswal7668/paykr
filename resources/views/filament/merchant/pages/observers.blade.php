<x-filament-panels::page><div wire:poll.15s class="space-y-6">
@foreach($this->health() as $label=>$status)<x-filament::section :heading="$label"><p>{{ $status }}</p></x-filament::section>@endforeach
<x-filament::section heading="Android observers">@forelse(\App\Models\AndroidDevice::where('is_active',true)->get() as $device)<p>{{ $device->device_name }} — {{ $device->last_heartbeat_at?->greaterThan(now()->subMinutes(3)) ? 'Connected' : 'Offline' }} — Last SMS {{ $device->last_observation_at ?? 'Never' }}</p>@empty<p>No paired phones.</p>@endforelse
@if(\App\Filament\Merchant\Pages\AndroidSms::canAccess())<a href="{{ \App\Filament\Merchant\Pages\AndroidSms::getUrl() }}">Pair an Android phone</a>@endif</x-filament::section>
<x-filament::section heading="Gmail"><p>{{ \App\Models\MerchantGmailConnection::first()?->status->value ?? 'DISCONNECTED' }}</p>@if(\App\Filament\Merchant\Pages\GmailConnection::canAccess())<a href="{{ \App\Filament\Merchant\Pages\GmailConnection::getUrl() }}">Manage Gmail connection</a>@endif</x-filament::section>
</div></x-filament-panels::page>
