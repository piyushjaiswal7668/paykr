<div class="space-y-4">
@foreach(\App\Models\MerchantCustomerBlock::where('customer_id', $customer->id)->get() as $block)<p>Store block: {{ $block->reason }}</p>@endforeach
@foreach(\App\Models\SecurityEvent::where('customer_id', $customer->id)->latest()->limit(50)->get() as $event)<p>{{ \App\Support\UserTimezone::format($event->created_at) }} — {{ $event->event_type }}</p><pre class="whitespace-pre-wrap">{{ $event->raw_message }}</pre>@endforeach
@foreach(\App\Models\AuditLog::where('entity_id', $customer->id)->latest()->limit(50)->get() as $entry)<p>{{ \App\Support\UserTimezone::format($entry->created_at) }} — {{ $entry->action }}</p>@endforeach
</div>