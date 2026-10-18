<x-filament-panels::page>
<div wire:poll.15s class="space-y-6">
@php($clock = $this->clockStatus())
<x-filament::section heading="Payment clock"><p>{{ $clock['healthy'] ? 'Running' : 'Not receiving a recent heartbeat' }}</p><p>Last heartbeat: {{ $clock['heartbeat'] ? \Carbon\CarbonImmutable::createFromTimestamp($clock['heartbeat'])->toIso8601String() : 'Never' }}</p>
@if($clock['recovery'])@foreach($clock['recovery'] as $name=>$count)<p>{{ \Illuminate\Support\Str::headline($name) }}: {{ $count }}</p>@endforeach @endif</x-filament::section>
<x-filament::section heading="Runtime services">@foreach($this->services() as $name=>$status)<p>{{ $name }}: {{ $status }}</p>@endforeach<a href="/horizon">Open Horizon queue dashboard</a></x-filament::section>
<x-filament::section heading="Recent failed jobs">@forelse($this->failedJobs() as $job)<p>{{ $job['failed_at'] }} · {{ $job['queue'] }} · {{ $job['uuid'] }}</p>@empty<p>No failed jobs.</p>@endforelse</x-filament::section>
</div></x-filament-panels::page>
