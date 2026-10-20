# Process configuration examples

These are examples, not installed services. Adapt PHP paths, user, repository path and log rotation.
Use Supervisor **or** systemd for Laravel workers, not both. Serve only `public/` through nginx and
PHP-FPM; bind Reverb and sidecars to loopback behind the authenticated proxy. The FPM Supervisor
entry starts its master as root, with pool workers configured as `www-data` in the FPM pool file.
An existing OS PHP-FPM service can replace that entry. Octane is not installed or required.

For systemd, copy `paykr@.service.example` to `/etc/systemd/system/paykr@.service`, and place one
root-owned, non-writable-by-www-data command file in `/etc/paykr/<instance>.env` for each instance:

| Instance | PAYKR_COMMAND value |
| --- | --- |
| horizon | `/usr/bin/php /srv/paykr/artisan horizon` |
| clock | `/usr/bin/php /srv/paykr/artisan paykr:clock` |
| reverb | `/usr/bin/php /srv/paykr/artisan reverb:start --host=127.0.0.1 --port=8080` |
| scheduler | `/usr/bin/php /srv/paykr/artisan schedule:work` |
| whatsapp-sidecar | `/usr/bin/npm --prefix /srv/paykr/services/whatsapp-sidecar start` |
| messages-sidecar | `/usr/bin/npm --prefix /srv/paykr/services/messages-sidecar start` |

Use the distribution's `php8.3-fpm.service` for FPM. The sidecar packages are implemented; entries remain opt-in until
private environment files and sender/device pairing are configured (see [services](../services/README.md)). Their own environment files must bind loopback and provide internal-token
authentication. Keep tokens and database credentials in protected environment files, never arguments.

The clock renews a five-second Redis lease. After SIGKILL a replacement may need to wait for that
lease to expire; `RestartSec=6` handles this. A graceful stop releases it immediately. Database row
locks and idempotent transitions protect against late/duplicate jobs. Clock startup logs recovery
counts and requeues live deadlines. `/health`, `/api/v1/health` and `/admin/system` display its cached
heartbeat; a heartbeat older than three seconds is unhealthy. The health endpoint's top-level `UP`
continues to mean application liveness, not payment-worker readiness.

Horizon reads queues in priority order `payments,webhooks,observers,default`. Its 80-second worker
timeout is below Redis's default 90-second retry interval. Keep that ordering when changing settings.
Gmail jobs have a 75-second timeout and per-merchant overlap/throttle protection. Run enough worker
processes for your observer load; slow observers already occupying workers cannot be preempted.

Deploy with migrations before restarting Horizon and the clock. The scheduler requeues failed
verification/webhook dispatches every minute and prunes processed Gmail IDs after 30 days. The clock
also finalizes due verification deadlines every tick, even if Redis job dispatch failed. No live
state depends solely on a timer, cache entry or process memory.

Phase 5 internal routes must not be forwarded by the public reverse proxy. Route `/internal/*`
only on a loopback listener, preserving the actual peer address; Laravel additionally checks the
loopback peer, internal token and observation HMAC. Public Gmail callbacks must use exactly the
configured redirect URI registered with Google. Apply the observer security-receipt migration
before restarting workers. The hourly scheduler prunes OAuth state and replay receipts.

## Local Android acceptance runtime (macOS)

Run these in separate terminals from the Paykr directory before testing payments:

```sh
/opt/homebrew/opt/php@8.3/bin/php artisan queue:work redis --queue=payments --sleep=1 --tries=3 --timeout=80
/opt/homebrew/opt/php@8.3/bin/php artisan paykr:clock
/opt/homebrew/opt/php@8.3/bin/php artisan schedule:work
```

The payments-only worker is enough for Android payment finalization. It does not deliver webhooks or process other observer queues; use the full supervised deployment above when those are required. These foreground processes are not login/reboot services. Keep their terminals running, stop with Ctrl-C, and restart them after code changes. The temporary Android HTTPS tunnel and its loopback server are separate processes.

To check actual delayed queue execution and recovery without another transfer:

```sh
/opt/homebrew/opt/php@8.3/bin/php tests/Support/check-background-finalization.php
```

This standalone check uses synthetic SMS in a temporary SQLite database and a unique Redis namespace. It starts independent worker/clock/scheduler processes, verifies SUCCESS occurs only after the grace deadline and releases the receiving slot, then removes its temporary database and Redis keys. It covers normal dispatch, lost-job clock recovery and scheduler requeue recovery. It does not read or modify the live merchant's payments or send external webhooks. Real MySQL contention and device offline behavior are separate checks.

## Merchant credit review

Merchant owners and managers can open `/merchant/{merchant-slug}/credit-reviews`. The page lists held credits with receiving account, amount, bank reference, SMS timestamp and reason. Open **Review and approve**, inspect the original evidence, select the exact matching account/amount order, re-enter the bank reference, record how the bank transaction was checked, and confirm the association.

Approval marks the selected order SUCCESS through the normal transition/webhook path. Expired, failed and cancelled orders additionally require the explicit **reopen as paid** checkbox. Automatic observation processing still cannot reopen a closed order. A newer active order on the same account keeps its slot. Repeated approvals are idempotent; used bank references, tenant/account/amount mismatches and unauthorized roles are rejected. The observation retains approver, timestamp, previous state, notes and reopening confirmation; HELD_CREDIT_APPROVED records the decision in the audit log. This is merchant verification, not independent bank API confirmation. No refund is initiated.

A reopened order has a new SUCCESS webhook event; an earlier cancellation/expiry/failure event remains historical. Merchant integrations must handle the later status update. Local payments-only workers enqueue but do not deliver webhook jobs; enable the documented full supervised queue setup for delivery.

Local dashboard testing uses `http://127.0.0.1:8012`; this dashboard is not exposed through the temporary Android-only tunnel. No APK update or database migration is required for this page.

## Phase 7: real-time runtime and checks

The existing Reverb supervisor entry now serves private `payment.{id}` and
`merchant.{id}.live` channels. `/broadcasting/auth` uses the web session/CSRF token;
checkout subscriptions additionally supply `X-Device-Id` + `X-Device-Token`, or a
customer bearer token bound to the payment. Merchant subscriptions require active
tenant membership. An unknown/unbound payment cannot be claimed through channel auth.
No credentials belong in channel names or URLs. Client-originated Reverb events are
rejected by default (`REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`).

Run Reverb alongside the payment worker, clock and scheduler:

```sh
php artisan reverb:start --host=127.0.0.1 --port=8080
php artisan queue:work redis --queue=payments --sleep=1 --tries=3 --timeout=80
```

Broadcasts use the payments queue, after commit, with three attempts and bounded
transport timeouts. Reload workers and the clock after code changes. Browser config
contains only the public Reverb key/host/port/TLS flag. For production, configure an
HTTPS/WSS endpoint with the reverse proxy forwarding Reverb WebSocket/API traffic,
and restrict `REVERB_ALLOWED_ORIGINS` to the deployed frontend hostnames. The temporary
Android Cloudflare tunnel deliberately does not expose the checkout or Reverb.

`/checkout/{id}` is a minimal payment-status shell for Phase 7; full checkout UI is
Phase 9. It establishes a device binding through the existing state endpoint and
stores its proof under `sessionStorage["paykr.checkout.<id>"]`. Opening an already
bound session without its proof remains rejected. A client with an existing device
or customer proof can seed `{deviceId, deviceToken, customerToken}` in that entry.
The reusable client refreshes on subscription/reconnect/events, polls every five
seconds while disconnected or subscription authorization fails, and reconciles at
least every thirty seconds while connected. Pending fetches are serialized.
Merchant pages receive the `paykr-live-update` Livewire event; Credit Reviews also
polls every five seconds so newly held, unattributed credits appear without waiting
for a payment transition. The remaining merchant pages are Phase 8.

Use Node 22 (the preinstalled Node 20.17 is too old for Vite 7):

```sh
PATH=/opt/homebrew/opt/node@22/bin:$PATH npm run build
php vendor/bin/pest tests/Feature/Realtime
PAYKR_NODE_PATH=/opt/homebrew/opt/node@22/bin/node \
PAYKR_BROWSER_PATH='/Applications/Brave Browser.app/Contents/MacOS/Brave Browser' \
php tests/Support/check-realtime.php
```

The browser check owns temporary ports 8019/8089, a disposable SQLite database and
unique Redis namespace. It starts/stops its own HTTP server, Reverb and worker,
verifies real broadcast delivery, blocked-WebSocket polling cadence and reconnection,
and deletes only its own test data. Set `PAYKR_BROWSER_PATH` to an installed Chromium
browser, or omit it when Playwright Chromium is installed. It makes no real payment.
The six MySQL-specific tests remain separate from the default SQLite suite.

### Phase 8 dashboard verification

The merchant panel is at `/merchant`; the platform panel is at `/admin` and requires
an enrolled authenticator. Owners/managers can configure stores; cashiers can use the
counter and read operational pages; developers can manage integration tools. Team
management is owner-only. New-user invitations return a password-setup link for the
owner to share. Existing users keep their existing passwords.

Build the shared Filament theme with `npm run build`. Run `php artisan storage:link`
for uploaded merchant logos. The `platform_options` migration stores white-label
fallbacks and OTP limits. Administrator APK publishing writes versioned releases and
updates the existing download; upload a signed APK built by the Android project.

Run `php tests/Support/check-panels.php` for an isolated SQLite browser smoke test.
Set `PAYKR_NODE_PATH` and `PAYKR_BROWSER_PATH` if Node/Chromium are not on the default
path. The test binds **127.0.0.1:8020**, refuses an occupied port, creates temporary
owner/cashier/TOTP-admin fixtures, and verifies branding persistence, counter confirmation,
role restrictions and admin pages. It does not send messages or mutate live payments.
Screenshots are written under `storage/app/private/evidence/phase8/`.

Livewire persistent middleware must retain tenant/audit context until the component
finishes. Both contexts are request-scoped; do not wrap only Livewire's replayed
middleware pipeline in a resetting tenant callback. Regression tests cover this lifecycle.

### Phase 9 checkout and browser SDK

Run `composer install` before `npm ci && npm run typecheck && npm run build`;
Filament's theme imports vendor CSS. The build emits the React checkout bundle and
`public/sdk/paykr.min.js` plus `public/sdk/integrity.json`. SDK aliases are `/paykr.js`,
`/sdk/paykr.js`, and `/sdk/paykr-checkout.js`. The merchant API Keys page shows the
script tag with the current SHA-384 integrity value. Update embedded integrity values
when deploying a changed SDK. Use the publishable key in the browser; keep secret keys
on the merchant server and verify payment webhooks before fulfilling orders.

Configure each merchant's exact allowed origins (scheme, host and optional port).
The SDK sends `X-Merchant-Key`; API authorization checks the requesting origin.
Checkout embedding and postMessage replies are restricted to configured origins.
Serve checkout over HTTPS (localhost also works for development): device registration
uses WebCrypto and IndexedDB to retain a non-extractable signing key. Browser storage
must be available. The React checkout uses Reverb with five-second polling fallback.

Run `php tests/Support/check-checkout.php` after building assets. The same
`PAYKR_NODE_PATH` and `PAYKR_BROWSER_PATH` overrides described above apply. It owns
ports 8022/8023, an isolated SQLite database and a local merchant/webhook listener.
OTP and signed Android credit fixtures are synthetic; test jobs run synchronously,
without writing jobs to the live Redis queue. It verifies desktop/mobile payment,
COD, counter, queue promotion, cancellation during verification, late-credit review,
duplicate-device restart and fraud blocking. Screenshots are saved under
`storage/app/private/evidence/phase9/`. The separate realtime harness now exercises
the React checkout against real local Reverb, including blocked sockets and reconnect.

### Phase 10 production guards and security checks

Production boot requires a valid HTTPS `APP_URL`, `APP_DEBUG=false`,
`PAYKR_ALLOW_LEGACY_MERCHANT_HEADER=false`, `SESSION_SECURE_COOKIE=true`,
`SESSION_HTTP_ONLY=true` and `SESSION_SAME_SITE=lax`, in addition to unique APP_KEY,
internal token and admin password. Do not copy development environment settings into
production. Keep internal routes and sidecars on private listeners as described above.
Checkout CSP permits scripts from its own origin and connects to its configured Reverb
host/port; set the browser-facing Reverb endpoint correctly. Use built Vite assets for
these acceptance checks (the checkout policy does not permit an external Vite dev server).

`tests/Feature/PaymentApiTest.php` includes a 200-payment/20-merchant burst and key/IP
rate-limit isolation. Run it with `tests/Feature/Payments/MySqlQueueConcurrencyTest.php`
against a dedicated MySQL database whose name ends in `_test`, with test credentials.
That concurrency suite runs `migrate:fresh` and must never point at live data. The suite
checks 50 independent concurrent customers and idempotent finalization/webhook behavior.
These local checks are not a production FPM/network capacity measurement.

See [security review](../docs/SECURITY-REVIEW.md) and the §16/§19.2 evidence tables in
[PARITY.md](../docs/PARITY.md). Repeat Composer and all three npm production dependency
audits at deployment; this phase's zero-advisory result is time-dependent.

### Dashboard training videos

Deploy the reviewed `storage/app/private/training/` directory with `catalog.json` and the
merchant/admin/integration subfolders. It is intentionally outside public storage. The
merchant Help → Training page and admin Training page stream authorized, signed URLs.
The optional `Paykr-Training-Library.zip` contains only the curated deployable library.
Do not deploy source captures or private credentials as public assets. See docs/TRAINING.md
for access rules, updates and playback checks. Keep PHP-FPM available for protected media;
if traffic grows, preserve authorization when introducing an internal nginx file handoff.

## Oracle production deployment modes

The `Deploy production` GitHub workflow now defaults to **graceful**. Its `mode` input
can explicitly select **maintenance** for a scheduled schema/sidecar release. These
script changes take effect after they are merged; they do not reconfigure an existing
Oracle server by themselves.

Before the first graceful deployment:

- Confirm nginx's PHP location uses `fastcgi_param SCRIPT_FILENAME
  $realpath_root$fastcgi_script_name;` (and `DOCUMENT_ROOT $realpath_root`) so an in-flight
  request uses a concrete release path. Validate the complete configuration with
  `sudo nginx -t` before reloading nginx. Do not replace the rest of your TLS, security,
  proxy, and ACME configuration with a partial example.
- Verify the deployment user can reload FPM. If not already covered, add only this scoped permission,
  using `sudo visudo -f /etc/sudoers.d/paykr-fpm-reload` and validate with `sudo visudo -c`:

  ```sudoers
  ubuntu ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
  ```

  Keep existing scoped backup/service permissions. Never grant arbitrary shell or
  unrestricted systemctl access. The deployment script checks this reload permission
  before preparing or switching a release.
- Keep systemd's `TimeoutStopSec=100` above Horizon's 80-second job timeout. SIGTERM
  lets workers finish their current jobs; systemd can force termination after that
  limit. Jobs and merchant requests must remain idempotent for crash recovery.
- Use `/srv/paykr/current/...` in the existing service command files for this release
  layout. Do not delete old releases still used by draining requests or running
  sidecars. Retention cleanup must account for those processes.

### Graceful (routine code releases)

Dependencies, caches, and assets are prepared in a separate release while HTTP and
workers stay live. Compiled Blade views are isolated under each release's
`bootstrap/cache/views`; building a new release does not clear the live view cache.
Previously published hashed build assets are retained for browsers with an older page
open. This does not make incompatible JavaScript/API changes safe: keep the browser,
API, queued-job payloads, and both PHP releases compatible during the handover.

The script refuses pending database migrations and changes to an enabled WhatsApp
sidecar **before touching live services**. It does not guess whether a migration is
backward compatible. Use a reviewed maintenance release for those changes. A future
expand/contract migration rollout can reduce that maintenance window, but is not
implemented by this script.

A live transactional MySQL dump and configuration backup are taken before activation.
There is no application maintenance flag and no PHP-FPM stop. The symlink switches
atomically, FPM reloads gracefully, and Horizon, clock, Reverb, and scheduler restart
sequentially. The WhatsApp sidecar stays connected. Background verification may briefly
pause during clock/worker handover; Reverb clients reconnect and fall back to polling.
This is graceful HTTP deployment, not a guarantee of uninterrupted service on one VM.

If post-activation health checks fail, the script restores the previous code symlink
and reloads/restarts services without restoring the database or entering maintenance.
New orders written during deployment remain in the database. Failed rollback/service
recovery still requires operator intervention; check the workflow logs and `/health`.

The live backup is **not** a consistent snapshot of mutable uploads or sidecar state.
Graceful mode therefore archives only `.env` alongside the database dump. Keep separate
scheduled storage/sidecar backups or coordinated snapshots. Maintenance mode retains
the existing stopped-writer storage backup. Keep backups private and monitor disk space;
retained old hashed assets and release directories need a separate retention policy.

### Maintenance (schema or sidecar changes)

Select `maintenance` explicitly when running the workflow. The script sets Laravel's
maintenance flag with `Retry-After: 30`, leaves FPM serving maintenance responses, stops
background services, backs up the database and shared files, applies migrations, and
activates/restarts the release. Customers can receive HTTP 503 during this window;
merchant backends must follow the [retry contract](../docs/INTEGRATION.md#retry-protection-during-deployments-and-temporary-outages).
If migrations have started and deployment fails, maintenance stays on for inspection;
the script does not attempt an unsafe automatic database rollback.

Validate deployment behavior locally with:

```sh
bash -n deploy/oracle-deploy.sh
python3 -m unittest discover -s tests/Release -p 'test_*.py'
```

The deployment tests use isolated directories and stubbed service/HTTP commands to
exercise activation, code rollback, migration refusal, maintenance failure handling,
asset retention, and secret permissions. They do not prove live nginx/FPM draining;
verify the server prerequisites and watch health/HTTP requests on the first rollout.
