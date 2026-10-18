# Paykr on Laravel 12 — build prompts

Paste the **Master prompt** once at the start of the build session (or put it in the new repo's `CLAUDE.md` / `AGENTS.md`). Then run the phases in order; paste one phase prompt at a time and do not start the next phase until the current phase's "Done when" list is green.

The agent must have read access to the old repository (this one) while building, so it can open the referenced files.

---

## Master prompt

```text
You are rebuilding "Paykr", a self-hosted UPI payment gateway, from a Node.js monorepo into a new
Laravel 12 application. The old repository is at <PATH_TO_OLD_REPO>; treat it as the behavioural
source of truth. The new repository is <PATH_TO_NEW_REPO>.

Read these files first and keep them open as your specification:
  - <OLD>/docs/laravel-migration/01-MIGRATION-SPEC.md   (architecture, domain rules, security, UI)
  - <OLD>/docs/laravel-migration/03-ROUTE-MAP.md        (every existing route and its new home)
  - <OLD>/docs/laravel-migration/04-ENGINEERING-STANDARDS.md (layers, DTOs, Actions/Services, thin
                                                          controllers, Filament reuse, error codes,
                                                          tests, architecture tests) — MANDATORY
  - <OLD>/docs/laravel-migration/90-APPENDIX-INVENTORY.md (every route, method, event, audit action,
                                                          env var, DB column and test case)
  - <OLD>/AGENTS.md                                      (public merchant integration contract)

Fixed decisions:
  - PHP 8.3, Laravel 12, Filament v5 (Livewire 4, Tailwind 4), MySQL 8, Redis, Laravel Reverb,
    Horizon, Pest. React 19 + TypeScript + Vite for the customer checkout only.
  - Multi-merchant: Filament panel /admin (platform owner) and /merchant (tenant = Merchant).
  - Customers are gateway-wide; bans are platform-wide; merchants can only block for their store.
  - WhatsApp OTP stays in a Node sidecar (BerryProtocol/Baileys). Google Messages SMS observer stays
    in a Node sidecar (Chrome CDP). Gmail observation moves into Laravel jobs.
  - paykr.js stays plain JavaScript; keep its public contract (script attributes, Paykr.pay,
    paykr:payment:* events, postMessage protocol).
  - Keep /api/v1 paths used by the Android app and merchant SDKs.

Working rules:
  0. Follow 04-ENGINEERING-STANDARDS.md for every file: FormRequest -> DTO -> Action/Service ->
     API Resource; controllers <= 15 lines and never touch models/DB; Filament pages and actions
     call the SAME Actions/Queries as the API (no duplicated business logic); every error is a
     PaykrException with an ErrorCode from the catalog, rendered by the single exception renderer;
     the Pest arch() tests from §8 must pass in every phase.
  1. Port behaviour exactly as specified, EXCEPT items marked "FIX" in the spec: implement the fixed
     behaviour and add a test proving the old bug cannot happen.
  2. Never add a fallback that crosses merchants. Every merchant-owned query is tenant-scoped.
  3. Money is integer paise (BIGINT). No floats in money paths.
  4. All payment status changes go through PaymentTransitionService (spec §5.2). Terminal is final.
  5. The verification pipeline result is final; only stage 6 may attribute a payment.
  6. Write Pest tests for every rule as you implement it. Port the matching old test cases listed in
     Appendix A8 (keep their names as test descriptions so coverage can be traced).
  7. No secrets in public responses; use explicit allow-lists. Encrypt secrets at rest.
  8. When the spec and the old code disagree, follow the spec and note it in MIGRATION_NOTES.md.
     When something is unclear, read the old code; if still unclear, stop and ask.
  9. Keep a checklist file docs/PARITY.md in the new repo: one line per route (from 03-ROUTE-MAP.md)
     and per Appendix A8 test, ticked when implemented and tested.
 10. After each phase: run `composer test` (incl. arch, contract and coverage gates),
     `./vendor/bin/pint --test`, `./vendor/bin/phpstan`, update PARITY.md, and summarise what was
     done, what is left, and any deviations.
 11. Every Action gets tests for its success path and for every ErrorCode it can throw; every
     /api/v1 route gets a contract test of its JSON keys; every Filament page gets an access test
     per role.
```

---

## Phase 0 — Project skeleton

```text
Phase 0. Create the Laravel 12 project skeleton.
- Install: filament/filament:^5, laravel/reverb, laravel/horizon, laravel/sanctum, pestphp/pest,
  larastan/larastan, laravel/pint, pragmarx/google2fa (or Filament's built-in MFA), predis or phpredis.
- Config: MySQL, Redis queue/cache/session, Reverb, Horizon. Timezone UTC.
- Create two Filament panels: AdminPanelProvider (/admin, users.is_platform_admin) and
  MerchantPanelProvider (/merchant, tenant = App\Models\Merchant via merchant_users pivot).
- Add config/paykr.php with every setting from spec §17 (windows, grace, retries, sidecar URLs,
  rate limits, internal token) with env() defaults.
- Add a production guard (spec §16.12): boot fails if APP_KEY, PAYKR_INTERNAL_TOKEN or the seeded
  admin password are template/empty values in production.
- Add GitHub Actions (or local script) running Pest, Pint, Larastan.
- Scaffold the engineering standards (04-ENGINEERING-STANDARDS.md): app/Domain/<Context> folders,
  Shared\Errors (PaykrException, ErrorCode enum with the full catalog, lang/en/errors.php), the
  single exception renderer in bootstrap/app.php (legacy /api/v1 variant + standard variant),
  RequestId middleware, ApiResponse helper, Actor value object, Money/MobileNumber/Vpa value
  objects, DTO base convention (spatie/laravel-data or readonly classes — choose one), the
  NotifiesPaykrErrors helper for Filament, and tests/Arch with every rule from §8.
Done when: both panels load with a seeded platform admin (2FA required) and an empty merchant;
`composer test` passes with a smoke test and the arch tests; GET /health returns {status: "UP"};
a test proves an unknown route, a validation failure and a thrown PaykrException all render the
documented error shapes (legacy and standard) with a request_id.
```

## Phase 1 — Database and models

```text
Phase 1. Implement the data model from spec §3 (MySQL migrations, Eloquent models, factories).
- All tables in §3.1 including NEW ones (users flag, merchant_users, merchant_api_keys,
  merchant_branding, merchant_payment_settings, merchant_gmail_connections, merchant_test_modes,
  customer_otp_challenges, observer_pairing_tokens, merchant_customer_blocks, whatsapp_sessions).
- UUID keys, BIGINT paise, encrypted casts for secrets, JSON casts, enums as PHP backed enums
  (PaymentStatus, PaymentMethod, SellingMode, ObserverSource, AttributionStatus, TicketStatus,
  SenderType, AuditActorType, MerchantRole).
- The one-active-VPA-per-merchant rule via the generated active_marker column + unique index.
- Tenancy: a BelongsToMerchant trait + global scope; Pest architecture test that every
  merchant-owned model uses it (spec §2.2.1).
- Seeders per spec §3.2 (local/testing demo merchant only; never customers).
- A one-off import command `paykr:import-sqlite <path>` that copies data from the old
  data/paykr.sqlite (split branding JSON into the new tables, move gmailConfig into
  merchant_gmail_connections encrypted, rehash nothing, mark old PBKDF2 passwords for rehash).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: migrations run on MySQL 8; factories exist for every model; tenancy architecture test
passes; importing the current data/paykr.sqlite succeeds and row counts match.
```

## Phase 2 — Core domain services (no HTTP yet)

```text
Phase 2. Port the domain layer from packages/common, packages/core and packages/db with unit tests.
Implement, in app/Domain:
- Money (§5.1), validators (mobile, email, VPA, password), UpiUri builder + intent URI,
  UPI provider registry (config/upi_providers.php from packages/common/src/upi-providers.js),
  QR SVG generator (port packages/core/src/qr.js or use a maintained library producing the same
  vector SVG; keep 220/260 px sizes).
- PaymentTransitionService (§5.2) with after-commit domain events.
- SlotQueueService (§5.6) using SELECT ... FOR UPDATE on the receiving account row.
- Parsers: BankSmsParser, BankEmailParser, AmountExtractor, ObservationNormalizer, fingerprint (§5.9).
  Port every regex verbatim; build Pest datasets from the old parser tests.
- VerificationPipeline (§5.10) returning the same result shape (stages 1..6). Pipeline is final.
- AttributionEngine (§5.11) incl. Level 1 grace job, Level 2 upgrade, 60-second upgrade window,
  late observation tickets; never crosses receiving accounts.
- FraudService / BanService (§5.12), DeviceSecurity (fingerprint hash, ECDSA verify P1363 + DER) (§5.5).
- WebhookService + DeliverWebhook job (§5.13) with the exact payload/headers and backoff.
- UpiHealthService (§5.15).
Port these old test files' cases (Appendix A8): packages/common/*, packages/core/* (core,
queue-timing, webhook-engine), packages/db/* (db, customer-reset), scripts/chaos-concurrency.test.js.
Add FIX tests: closed session never becomes SUCCESS; pipeline REJECTED never attributes;
observation on merchant A's account can never attribute merchant B's session; counter-decline on a
closed session is rejected; every terminal transition dispatches exactly one webhook.
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: all listed tests pass, including a 50-concurrent-customer queue test on MySQL.
```

## Phase 3 — Clock worker, jobs and recovery

```text
Phase 3. Implement timing and background processing (§5.7).
- Jobs: ExpireActivePayment, FailProcessingPayment, FinalizeLevel1Payment, DeliverWebhook,
  PollGmail, PollGmailNow (throttled), ReattributePendingObservations.
- Command `paykr:clock`: single instance via cache lock, 1-second loop doing the four duties in
  §5.7, recovery on start, heartbeat written to cache (shown on /health and the admin System page).
- Horizon queues: `payments` (high), `webhooks`, `observers`, `default`.
- Supervisor/systemd example configs in deploy/ for: php-fpm/Octane, horizon, paykr:clock, reverb,
  scheduler, whatsapp-sidecar, messages-sidecar.
Port old tests: apps/payment-api/src/__tests__/cod-parallel-timer-decoupling.test.js and the timing
parts of queue-timing / payment-api tests.
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: killing and restarting paykr:clock mid-payment recovers correctly (test), expiry and
processing failure happen within 1 s of the deadline, and no timer state lives only in memory.
```

## Phase 4 — Public API (payments, customers, devices, checkout actions)

```text
Phase 4. Implement the integration and checkout API (spec §5.3, §5.4, §5.5, §5.8, §6.1) using
03-ROUTE-MAP.md rows marked "Same path".
- API keys: publishable (pk_) + secret (sk_), live/test, hashed, origin check for publishable keys,
  rate limits (§16). Accept legacy X-Merchant-Id only behind a config flag for migration.
- Customer OTP flow with customer_otp_challenges, WhatsApp sidecar client (stub in tests),
  devOtp only in local/testing, attempt + send limits, masked check-user.
- Device registration/verify; device access middleware for /api/v1/checkout/{id}/* (device id +
  device token; no X-Internal-Render bypass).
- Every checkout action in §5.8 with its FIX.
- Form Requests for validation; API Resources with explicit allow-lists (branding §10.6).
Port old tests: payment-api, auth-matrix, whatsapp-berry-auth, device-binding-duplicate-session,
selling-modes-and-cod, counter-payments (API parts), upi-server-health-and-pc-ui (API parts),
whitelabel-branding (API parts), admin-auth-and-final-states (equivalents).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: all ported tests pass and a Pest test proves /state never contains gmail/test-mode keys.
```

## Phase 5 — Observers: Android, Gmail, internal sidecar endpoints

```text
Phase 5. Implement observation intake (§13, §15, §6.2).
- Android: pairing with observer_pairing_tokens (single-use, 10 min, hashed), device HMAC
  verification (mandatory, 60 s drift), heartbeat, PROCESSED_NON_BANK, APK download from storage.
  The current APK in <OLD>/apps/android-observer-app/paykr-sms-gateway.apk must pair and post
  successfully against the new API (write an integration test that replays its exact requests from
  <OLD>/apps/android-observer-app/driver.js).
- Gmail: per-merchant OAuth (state signed), encrypted tokens, PollGmail job, dedupe of message ids,
  test connection, disconnect with revoke; cadence rules in §15.
- Internal endpoints /internal/observations and /internal/active-window (localhost + internal token),
  merchant-scoped.
Port old tests: android-observer, gmail-observer, oauth-view (Gmail OAuth result page), sms-observer,
sms-gmail-verification-levels, fraud-ban-pipeline.
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: SMS from a paired phone, a Gmail credit alert, and a sidecar observation each complete a
payment through the pipeline; an unsigned or replayed request is rejected; Gmail tokens never appear
in any HTTP response.
```

## Phase 6 — Node sidecars

```text
Phase 6. Build the two sidecars in services/ (Node 22+, no build step, node:test).
- whatsapp-sidecar (§14.1): port apps/payment-api/src/services/whatsapp-berry.service.js to an HTTP
  service on 127.0.0.1 with endpoints /status, /connect, /pairing-code, /disconnect, /send,
  /send-otp; pushes status to Laravel /internal/whatsapp/status; X-Paykr-Internal-Token auth;
  remove the hard-coded D:/ module paths; test-mode simulation rules.
- messages-sidecar (§14.2): port apps/sms-observer + packages/observer-sdk/src/browser-launcher.js
  with one Chrome profile per merchant; endpoints /profiles/{merchantId}/pair|status|sweep; forward
  the real sender + raw text to /internal/observations; remove the "amount-only" fallback.
- Laravel clients for both (HTTP with 1–2 s timeouts, circuit breaker), feature-flagged.
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: OTP is delivered through the sidecar in a manual test; the sidecar's own tests pass;
Laravel works (degraded, RED/YELLOW health) when either sidecar is down.
```

## Phase 7 — Real-time (Reverb)

```text
Phase 7. Replace SSE with Reverb (§7).
- Channels: private payment.{id} (authorize with device id + device token or customer token bound
  to the session) and private merchant.{id}.live (merchant users of that tenant).
- Broadcast events listed in §7 from the domain events (after commit).
- Keep GET /api/v1/checkout/{id}/stream and /events returning 410 with a hint (or an SSE fallback
  if required by integrators).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: a Pest broadcast test covers each event, and the checkout updates live in the browser
E2E test with a 5-second polling fallback when the socket is blocked.
```

## Phase 8 — Filament panels

```text
Phase 8. Build both Filament panels per spec §8 (platform admin) and §9 (merchant), mapping every
row of 03-ROUTE-MAP.md whose Laravel location is a Filament page or action.
- Merchant panel: Live monitor (stats + current slot with countdown + queue + Clear queue),
  Counter desk (live, confirm/decline), Transactions (detail with audit timeline, observations,
  webhooks), Observers, Brand identity (with live phone mockup + quick palettes), Receiving
  accounts, Payment methods, Payment simulator (test mode with countdown, customer search scoped to
  this merchant, SMS/Gmail injectors, stage-by-stage result viewer, history), Webhooks & API keys
  (deliveries, resend, test webhook, rotate secret), Android SMS (single-use QR auto-refresh,
  devices, unpair, parse-only test tool), Gmail connection, Customers (blocks, own security events),
  Support tickets, Team members.
- Platform admin panel: Dashboard, Merchants (+ API keys, suspend, impersonate), Customers (ban,
  unban, evidence), Security (banned users, events, history), WhatsApp sender (QR, pairing code,
  reconnect, disconnect, test message), Payments (all), Webhook deliveries, Audit log, System
  (Horizon, clock heartbeat, Reverb, sidecars, failed jobs), Platform settings (white-label
  defaults, OTP limits, APK upload).
- Role permissions per §2.1 via Filament policies; every mutation writes an audit log with the real
  user id and IP.
- Use docs/demo/img/*.jpg in the old repo as the visual reference for layout and wording.
Port old tests: merchant-admin, control-center (only what remains: System page), developer-test-mode,
developer-payment-simulator, counter-payments (UI parts), whitelabel-branding (UI parts).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: every Filament row in 03-ROUTE-MAP.md is ticked in PARITY.md with a test; a cashier
cannot open branding/webhooks; merchant A cannot see merchant B's data (tests).
```

## Phase 9 — React checkout and paykr.js

```text
Phase 9. Build the customer checkout in React (§10) and port paykr.js (§11).
- resources/checkout: React 19 + TypeScript + Vite; Blade shell at GET /checkout/{id} with escaped
  initial state; mobile bottom-sheet and desktop two-column layouts matching
  <OLD>/apps/checkout-portal/src/views/checkout.html and docs/demo/img (c*, p*, f* screenshots).
- Implement every view and behaviour in §10.2–10.5: splash, sign-in (mobile → signup → OTP with
  auto-submit and 30 s resend), saved accounts / switcher, device identity (WebCrypto ECDSA +
  fingerprint signals), method selector, UPI QR with timer and Payment Done (desktop + mobile after
  1 minute), queue view, processing view, success (white-labelled), failed/expired/cancelled with
  retry, COD, cash/card counter, duplicate-session (end and restart), blocked/fraud notice,
  help modal, cancel modal, order details drawer, downtime alert with cash alternative.
- Reverb client with polling fallback; postMessage protocol (§10.4) targeted at the validated
  merchant origin.
- paykr.js: port apps/checkout-portal/src/sdk/paykr.js keeping every attribute, method, event and
  redirect; add origin checks; serve /paykr.js, /sdk/paykr.js, /sdk/paykr-checkout.js with CORS;
  minified build + SRI hash shown in the merchant panel.
Port old tests: checkout-portal, paykr-universal-integration, merchant-sdk, payment-help (help modal
content replaces the help portal).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: Playwright E2E runs the full flow on desktop (1440×900) and mobile (390×844) viewports:
store page → paykr.js modal → OTP (devOtp) → QR → simulated Android SMS → success → webhook
received; plus COD, counter, queue of two customers, cancel during grace (stays CANCELLED),
late payment ticket, fraud injection ban, duplicate session on second device.
```

## Phase 10 — Hardening and security review

```text
Phase 10. Go through spec §16 and §19.2 line by line and prove each item with a test or config.
- Rate limits, CSP (frame-ancestors from merchant allowed_origins), secure cookies, SSRF guard for
  webhooks, encrypted secrets, hashed tokens, production template-secret guard, audit coverage.
- Port scripts/production-infrastructure.test.js (production config guards, env validation).
- Run a security review of the whole codebase (authz on every route/page, tenant scoping, mass
  assignment, XSS in Blade/React, SQL injection, IDOR on payment/device ids).
- Load test: 50 concurrent customers on one VPA; 200 payment creations/min across 20 merchants.
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: every item in §16 and §19.2 has a linked test in PARITY.md and the review finds no
high/critical issues.
```

## Phase 11 — Data migration and cut-over

```text
Phase 11. Prepare cut-over from the Node system.
- Final run of paykr:import-sqlite against a copy of production data; verify counts, a sample of
  sessions/observations, and that webhook secrets and Gmail tokens decrypt.
- Keep /api/v1 paths and the paykr.js contract identical; publish the new merchant SDK versions.
- Runbook in docs/CUTOVER.md: freeze window, DNS switch, sidecar re-pairing (WhatsApp QR, Google
  Messages QR per merchant), Android devices re-pair with new single-use QR, rollback plan.
- Demo store: rebuild as a separate example project or keep the Node one pointed at the new API;
  port coin-wallet-store.test.js against it (spec §19.3).
- Smoke test checklist from AGENTS.md integration examples (Node, PHP, Python, Go webhook verify).
Standards: all new code follows 04-ENGINEERING-STANDARDS.md (DTO + Action/Service, thin
controllers/Filament, catalog error codes); arch tests and coverage gates pass.
Done when: PARITY.md is 100% ticked (or each remaining item is explicitly accepted as dropped in
spec §18), and the E2E suite passes against a staging deployment.
```

