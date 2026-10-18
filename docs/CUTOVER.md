# First production deployment and rollback

## Scope decision

The Node project never served production. The owner has explicitly omitted historical
data import, freeze window, Node DNS cut-over, and source-to-target reconciliation.
The existing Laravel database, merchant configuration and paired phone remain intact.
A new production deployment gets its own environment and empty production database;
this does not authorize clearing the current Laravel environment. Hosted staging is
also deferred by the owner in favor of an isolated local rehearsal.

## Prepare a release

1. Choose the HTTPS application domain and a separate staging/test merchant. Provision
   MySQL 8, Redis, PHP 8.3, Node 22.13+, nginx/PHP-FPM, process supervision and backups.
   Serve only `public/`. Deny public `/internal/*`; keep sidecars, MySQL and Redis private.
2. Store unique APP_KEY, PAYKR_INTERNAL_TOKEN and administrator credentials in a protected
   secret store/environment file. Set APP_ENV=production, APP_DEBUG=false, APP_URL to HTTPS,
   secure HttpOnly SameSite=Lax cookies and legacy merchant headers disabled. Preserve
   APP_KEY across releases: changing it prevents existing provider secrets from decrypting.
3. Install locked dependencies (`composer install --no-dev --prefer-dist --optimize-autoloader`,
   `npm ci`), build (`npm run typecheck && npm run build`), and install sidecar dependencies
   only for enabled transports. Verify current dependency audits and the release tests in CI.
4. Take an encrypted MySQL backup and private storage/config backup before migrations.
   Include Android releases and provider session storage; retain checksums and APP_KEY
   separately with restricted access. Rehearse restoration into a separate database.
5. Apply `php artisan migrate --force`. Seed the first administrator only with configured
   credentials; production seeding intentionally creates no demo merchant/customer. Enroll
   administrator TOTP, create the merchant, receiving account, allowed origins, keys and
   webhook URL. Verify exact bank/UPI details rather than copying demo defaults.
6. Run `php artisan storage:link`, cache configuration/routes/views as appropriate, and
   start supervised Horizon, clock, scheduler, Reverb and enabled private sidecars using
   deploy/ templates. Configure public WSS routing for the browser-facing Reverb endpoint.
   Restart workers after each release so HTTP and background jobs use the same code.

## Pairing and merchant integration

A separate production database needs newly issued single-use Android pairing tokens.
Pair the intended production phone, verify heartbeat, then check an authorized bank-credit
observation. Do not revoke the current development pairing until the production transport
is deliberately ready. WhatsApp may require linking its sender account in the production
sidecar session. Gmail needs the exact production OAuth callback registered with Google
and per-merchant consent. Google Messages remains disabled for this installation; Android
is the selected transport. Never copy signing keys into browser scripts or issue static
production enrollment secrets.

Use the v2 merchant SDK with a server-side secret key; merchant-ID-only authentication is
not supported. The browser script uses a publishable key and exact allowed origins.
Copy the current SHA-384 integrity tag from the developer panel after every SDK deployment.
For webhooks, verify the raw body, match payment/order/amount/currency, and credit once per
order. Do not trust browser callbacks or success URL parameters as payment proof.

## Rehearsal and launch gate

The approved local rehearsal uses disposable SQLite fixtures and ports 8022/8023:
`php tests/Support/check-merchant-demo.php` and `php tests/Support/check-checkout.php`.
Set PAYKR_NODE_PATH / PAYKR_BROWSER_PATH if needed. The demo check covers SDK API calls,
wallet verification/signature/replay behavior and store-button navigation to Laravel.
The checkout matrix covers OTP, synthetic signed Android credit, webhook, desktop/mobile,
COD/counter, queue, cancellation, late credit and fraud. No real funds are moved.
`node --test tests/Integration/wallet.test.mjs` verifies credit persistence after restart.
`node tests/Integration/webhook-verifiers.mjs` verifies PHP/Python and, with PAYKR_GO_PATH,
Go raw-body HMAC behavior; the SDK tests verify Node with the same signature algorithm.

Before accepting real traffic on the future host, repeat checkout/realtime checks with
production-equivalent HTTPS routing and an isolated test merchant, confirm worker readiness,
backup restoration, delivery retries and monitoring. Perform an explicitly authorized small
live payment and confirm both bank evidence and merchant fulfilment. Local rehearsal does
not establish hosted capacity, DNS/TLS configuration or provider connectivity.

## Rollback

Disable creation of new orders during a failed launch. Keep monitoring pending credits and
retain observations/webhook delivery records. Switch the application release to the previous
known-good artifact only if its code supports the current schema; restart all workers and
recheck health. Prefer forward schema fixes. Never run migrate:fresh or blindly roll back a
migration on a payment database.

If restoring a backup is unavoidable, stop writers, take a fresh forensic backup, restore
into a separate database and reconcile every payment/credit/webhook since the backup before
resuming. A database restore can otherwise lose already-paid orders and create duplicate
fulfilment. There is no production Node system to switch back to. First-launch failure means
keeping the service closed until Laravel's verified release is restored.

## SDK release

Version 2.0.0 is prepared as an installable npm tarball under storage/app/private/releases/.
Registry publication is not required for this local rehearsal and has not been performed.
Install the tarball privately or publish from the authorized package-owner account when
ready. Keep release hashes with deployment records; do not include .env, wallet data or
provider sessions in the package.
