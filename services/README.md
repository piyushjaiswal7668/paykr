# Phase 6 sidecars

Requires Node 22–26 (no build step). Laravel still runs with both services disabled.
Use a Node version supported by BerryProtocol's installed native SQLite dependency; `npm ci`
must run under the same Node major used to start the WhatsApp service.

```sh
node --version
npm ci --prefix services/whatsapp-sidecar
node --test services/tests/*.test.js
```

Each process reads its environment, not Laravel's `.env`. Set the same `PAYKR_INTERNAL_TOKEN`
(at least 32 characters) in protected process environment files. Never put it in URLs, shell
arguments, browser JavaScript or source control. Both HTTP listeners bind **127.0.0.1 only**.
The default internal Laravel URL is `http://127.0.0.1:8000`; set `LARAVEL_INTERNAL_URL` if needed.
Keep `/internal/*` inaccessible from the public proxy.

| Process | Default port | Start | Laravel flag |
| --- | --- | --- | --- |
| WhatsApp | 6101 | `npm --prefix services/whatsapp-sidecar start` | `WHATSAPP_SIDECAR_ENABLED=true` |
| Google Messages | 6102 | `npm --prefix services/messages-sidecar start` | `MESSAGES_SIDECAR_ENABLED=true` |

Use a **different** `SIDECAR_DATA_DIR` for each process, outside the webroot. Defaults are
`~/.local/share/paykr/whatsapp` and `~/.local/share/paykr/messages`. The process umask is 077.
Back up these private directories securely; they contain linked-device credentials. Do not copy
credentials from the old Node application. No old service or source files are modified.
`PORT` can override each listener. Restart Laravel workers after changing its configuration.

## WhatsApp

All routes require `X-Paykr-Internal-Token`:

- GET `/status`: connection state, sender number, platform, session ID, QR data URL and pairing code.
- POST `/connect` with `{}`: initializes the SDK, waits up to eight seconds for a QR.
- POST `/pairing-code` with `{"phoneNumber":"91…"}`: waits up to nine seconds for a linking code.
- POST `/disconnect` with `{}`: logs out and removes this sidecar's auth and store files.
- POST `/send` with `{"to":"91…","message":"…"}`.
- POST `/send-otp` with `{"to":"91…","code":"123456","name":"Customer","appName":"Paykr"}`.

Scan the QR using the **sender** phone: WhatsApp → Settings → Linked devices → Link a device.
For phone-number pairing, choose “Link with phone number instead” on that screen and enter the
sidecar's code. Only an authenticated operator should see the QR or pairing code. The admin panel
controls are Phase 8; the Phase 6 Laravel clients are available for those controls to call.

`APP_ENV=testing`, or recipients beginning `910000000000`, `0000000000`, `919999999999`, simulate
sending and return `simulated: true`. Real sends never fall back to simulated delivery. A successful
SDK send means WhatsApp accepted the message; confirm receipt on the phone for manual acceptance.
The legacy `queued: true` response means delivery is pending/retryable, **not a durable resend queue**.
OTP expiration still belongs to Laravel. The sidecar does not log OTPs, message text or pairing codes.
Status is pushed to `/internal/whatsapp/status` on changes and every 30 seconds; Laravel persists no
QR/pairing secrets. Live status must be checked before treating a stored sender status as connected.

Laravel's clients use a one-second connect deadline and two-second total deadline, with a 15-second
circuit cooldown after failure. Initial connect/pair operations may outlast that client deadline;
poll `/status` after cooldown instead of restarting pairing. Sidecar failures return undelivered
OTP dispatches and do not crash customer authentication.

## Google Messages

Also set `MESSAGES_SIDECAR_ENABLED=true` in the **sidecar** environment. Install Chrome, or set
`CHROME_PATH` to its executable. A graphical desktop is required for pairing/background windows.
Chrome's sandbox stays enabled. Each merchant UUID owns `profiles/<uuid>` and its own ephemeral
loopback debugging port; no shared Chrome profile or default browsing session is used.

- POST `/profiles/{merchantId}/pair` with `{}` opens Google Messages authentication visibly.
- GET `/profiles/{merchantId}/status` returns pairing/freshness/counters without private profile paths.
- POST `/profiles/{merchantId}/sweep` with `{}` performs an immediate observation sweep.

Pair from Android Google Messages → Device pairing. The two-second monitor moves paired windows
offscreen; paired profiles restore in the background after restart. Extraction runs every three
seconds and rotates the ten most recent visible conversations. It uses complete message bubbles,
the conversation's actual sender and a parseable message timestamp. Missing sender/time, outgoing
messages, old messages and truncated previews are skipped. There is no document-body scan,
fabricated sender or amount-only matching. Laravel's verification pipeline makes payment decisions.
Google changes its DOM; validate the selectors against a paired account before production use.
A conversation with no supported timestamp is deliberately ignored rather than treated as fresh.

The forwarded JSON contains merchantId, source, sender, rawText, observedAt and messageHash.
`X-Signature` is lowercase HMAC-SHA256 of **exact UTF-8 body + ':' + X-Timestamp**, with the internal
token as key; `X-Timestamp` is epoch milliseconds. Failed forwards remain eligible for retry.
Liveness becomes stale/unavailable on extraction or transport failure. Fresh Android or Google
Messages plus Gmail yields GREEN UPI health; one source yields YELLOW, neither yields RED.
WhatsApp availability affects OTP delivery independently of UPI verification health.

The CDP adapter uses the official [Runtime.evaluate](https://chromedevtools.github.io/devtools-protocol/tot/Runtime/#method-evaluate)
and [Browser window methods](https://chromedevtools.github.io/devtools-protocol/tot/Browser/#method-setWindowBounds).

## Acceptance

Automated tests cover authenticated HTTP, exact-byte HMAC, input limits, isolation, retry/dedup,
QR/pairing lifecycle, branded OTPs, simulation, circuit cooldown, and RED/YELLOW health on outages.
Phone delivery and live Google Messages DOM extraction are separate manual checks. Do not record
simulation as live delivery. Process-manager examples are supplied in `deploy/`; none are installed
by this phase. Keep services disabled until their own pairing and acceptance checks succeed.
