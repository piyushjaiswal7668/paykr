<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}"><meta name="referrer" content="no-referrer">
<title>Payment status · Paykr</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="bg-slate-50 text-slate-900"><main class="mx-auto mt-16 max-w-lg rounded-xl bg-white p-8 shadow" data-checkout-id="{{ request()->route('id') }}">
<h1 class="text-2xl font-semibold">Payment status</h1>
<p class="mt-4" data-payment-status role="status" aria-live="polite">Loading…</p>
<p class="mt-2" data-payment-detail></p>
<p class="mt-6 text-sm text-slate-500" data-connection-status>Connecting…</p>
<p class="mt-4 text-sm text-red-700" data-payment-error role="alert"></p>
</main><x-realtime-config /></body></html>
