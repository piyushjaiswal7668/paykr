<div class="space-y-6">
<p>Order {{ $payment->order_id }} · {{ $payment->status->value }} · ₹{{ number_format($payment->amount_paise / 100, 2) }}</p>
<h3 class="font-semibold">Audit timeline</h3>
@foreach (\App\Models\AuditLog::where('entity_id', $payment->id)->orderBy('created_at')->get() as $entry)
<p>{{ $entry->created_at }} — {{ $entry->action }} — {{ $entry->actor_id }}</p>
@endforeach
<h3 class="font-semibold">Bank observations</h3>
@foreach ($payment->observations()->orderBy('ingested_at')->get() as $entry)
<p>{{ $entry->source->value }} · {{ $entry->bank_reference_number }} · {{ $entry->observed_at }}</p><pre class="whitespace-pre-wrap">{{ $entry->raw_payload['text'] ?? '' }}</pre>
@endforeach
<h3 class="font-semibold">Webhook deliveries</h3>
@foreach ($payment->webhookDeliveries()->get() as $entry)
<p>{{ $entry->event_type->value }} — {{ $entry->is_successful ? 'Delivered' : 'Pending / failed' }} — {{ $entry->attempt_count }} attempts</p>
@endforeach
</div>
