# Phase 10 security review — 2026-10-18

Scope: Laravel routes/controllers, domain authorization and tenant boundaries, Filament
pages/actions, React checkout/browser SDK, observer sidecars and production configuration.
This is a source review and automated local verification, not a production penetration test.
No high/critical issue remains identified in the reviewed paths. Production deployment
configuration and provider operation still require the cut-over checks.

## Findings resolved

- Payment API throttling used an IP-wide bucket. It now keys the authenticated API key
  hash and IP, preventing one merchant from exhausting another merchant's allowance.
  Header aliases and bearer authentication share a bucket for the same key.
- Production startup allowed unsafe runtime settings despite rejecting template secrets.
  It now refuses non-public/HTTP APP_URL, debug mode, legacy merchant-header authentication,
  and cookies without Secure, HttpOnly and SameSite=Lax.
- Checkout CSP only restricted embedding. It now restricts executable scripts to self,
  network connections to self/configured Reverb, forms to self, and prohibits objects
  and base elements. Merchant origin parsing rejects malformed URLs before building CSP.
  Inline styles remain enabled for existing React branding and layout; scripts do not.
- JSON CSRF failures were converted to generic HTTP 500. They now use catalog code
  CSRF_TOKEN_MISMATCH, HTTP 419, and a safe session-expiry message.

## Review evidence and limits

API requests use FormRequests/DTOs and explicit resources. Merchant-owned models have
fail-closed tenant scopes and prevent merchant reassignment. Unscoped lookups for public
checkout IDs are followed by device/customer authorization; public HTML contains only
bootstrap display fields. Platform-wide queries require the admin panel and persisted
admin status. Panel service authorization is repeated on mutations, including Livewire;
impersonation retains the real administrator in audits. TOTP and separate admin guard
remain enforced. Public APK/health/SDK endpoints are deliberately unauthenticated.

Database operations use bound Eloquent queries; the sole application whereRaw is the
constant fail-closed predicate. User input is not passed to shell execution. Blade's raw
JSON bootstrap uses JSON_HEX escaping; React text rendering and SDK origin/source checks
protect merchant-controlled display and payment results. Webhooks require public HTTPS
DNS results outside local/testing, pin the validated IP, and disable redirects/proxies.
Tokens are hashed and provider/signing secrets encrypted. Tests exercise cross-tenant
IDs, forged admin actions, signature/replay checks, rejection-before-attribution and
closed-order review. Detailed requirement links are in PARITY.md.

The legacy production-infrastructure suite's five-service hostname/port matrix is
intentionally replaced by one Laravel APP_URL and private sidecars. ProductionSecretsTest
and the production-seeding test cover authoritative HTTPS configuration and no demo data;
ObserversApiTest covers versioned, expiring single-use pairing and signed transport;
GmailOAuthTest covers per-merchant encrypted tokens and callback state; SidecarsTest and
services/tests cover private authenticated sidecar operation. Merchant-editable tunnel
commands and automatic legacy browser launch remain removed.

Load verification uses the dedicated MySQL test database, not live data:
MySqlQueueConcurrencyTest exercises 50 independent PHP connections behind a start barrier,
including one active slot, 49 ordered waiters, concurrent terminal calls and deduplicated
webhooks. PaymentApiTest creates 200 payments across 20 merchants in under 60 seconds
through the HTTP kernel with middleware; this is an application-level burst check, not
an internet-facing HTTP/FPM capacity benchmark. All 11 MySQL tests passed (480 assertions).

Composer and npm production dependency audits reported zero advisories for Laravel,
checkout, WhatsApp and Messages dependencies at review time. This is a point-in-time
advisory result, not a guarantee against undisclosed vulnerabilities. Browser checks
passed the full checkout matrix and real Reverb live/polling/reconnect modes under CSP.

Final gates: 291 PHP tests passed (six MySQL-only skips in the SQLite run), 3,011
assertions, 93.4% overall and 95.20% domain coverage. The separate MySQL run passed;
application/domain PHPStan and Pint pass. Phase 10 is complete within the local review
and test scope above. Phase 11 is data migration and cut-over preparation.
