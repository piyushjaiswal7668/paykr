@if(session('paykr_impersonator'))
<div role="alert" class="p-4 bg-amber-100 text-gray-900">Administrator support session — expires after 15 minutes.
<form method="POST" action="/admin/end-impersonation">@csrf<button type="submit">End support session</button></form></div>
@endif
@if (\Filament\Facades\Filament::getTenant())
<div data-merchant-live="{{ \Filament\Facades\Filament::getTenant()->getKey() }}" hidden></div>
<x-realtime-config />
@vite('resources/js/app.js')
@endif
