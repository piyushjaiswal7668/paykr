<script type="application/json" id="paykr-realtime-config">{!! json_encode([
    'key' => config('broadcasting.connections.reverb.key'),
    'host' => config('broadcasting.connections.reverb.options.host'),
    'port' => config('broadcasting.connections.reverb.options.port'),
    'tls' => config('broadcasting.connections.reverb.options.useTLS'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
