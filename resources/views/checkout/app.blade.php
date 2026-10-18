<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $bootstrap['state']['merchantName'] }} — Secure checkout</title>@vite('resources/checkout/main.tsx')</head>
<body><div id="root"></div><script id="checkout-bootstrap" type="application/json">{!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script><x-realtime-config /></body></html>
