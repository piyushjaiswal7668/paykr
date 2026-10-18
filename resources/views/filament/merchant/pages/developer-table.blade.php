<x-filament-panels::page>
@if($result)<x-filament::section heading="Action result">@if(isset($result['key']) || isset($result['secret']))<p>Copy this credential securely. It is not included in audit logs.</p>@endif<pre class="whitespace-pre-wrap break-all">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre></x-filament::section>@endif
{{ $this->table }}
@if($this instanceof \App\Filament\Merchant\Pages\ApiKeys && is_file(public_path('sdk/paykr.min.js')))
<x-filament::section heading="Website integration">
<p>Add your website origin in Webhooks, then replace the placeholder with a publishable key. Keep secret keys on your server.</p>
<pre class="whitespace-pre-wrap break-all">{{ '<script src="'.url('/paykr.js').'" data-paykr-key="YOUR_PUBLISHABLE_KEY" integrity="sha384-'.base64_encode(hash_file('sha384', public_path('sdk/paykr.min.js'), true)).'" crossorigin="anonymous"></script>' }}
{{ '<button data-paykr-pay data-order-id="ORDER-123" data-amount="100.00">Pay now</button>' }}</pre>
<p>Use your server’s signed webhook to confirm payment. Browser callbacks update the interface only.</p>
</x-filament::section>
@endif
</x-filament-panels::page>
