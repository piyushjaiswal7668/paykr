<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Gmail connection · Paykr</title>
<style>body{font:18px/1.6 system-ui,sans-serif;background:#f5f7fb;color:#172033;margin:0;padding:8vh 24px}main{max-width:560px;margin:auto;padding:32px;background:white;border-radius:16px}h1{font-size:28px;line-height:1.2}a{color:#2563eb}</style></head>
<body><main>
@if ($status === 'CONNECTED')
<h1>Gmail connected</h1><p>Paykr can now check credit alerts for your store. You can close this window and return to the merchant dashboard.</p>
@elseif ($status === 'DENIED')
<h1>Connection cancelled</h1><p>No new Gmail access was granted. Return to the dashboard to try again.</p>
@else
<h1>Gmail connection could not be completed</h1><p>Return to the merchant dashboard and start a new connection request.</p>
@endif
<a href="/merchant">Return to merchant dashboard</a>
</main></body></html>
