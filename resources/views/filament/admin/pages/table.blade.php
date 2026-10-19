<x-filament-panels::page>
@if(isset($result['invitationUrl']))
@include('filament.invitation-link', ['invitationUrl' => $result['invitationUrl']])
@elseif($result)
<x-filament::section heading="Action result"><pre class="whitespace-pre-wrap break-all">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre></x-filament::section>
@endif
{{ $this->table }}
</x-filament-panels::page>
