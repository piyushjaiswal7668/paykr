# Paykr — Laravel 12 Migration Specification

> **Audience:** the engineer or AI coding agent rebuilding Paykr on Laravel 12 + Filament v5 + React.
> **Source of truth:** the current Node.js monorepo (`apps/*`, `packages/*`). Every behaviour below was read from that code.
> **Completeness rule:** every route, class method, event, audit action, environment variable and test case in
> [`90-APPENDIX-INVENTORY.md`](90-APPENDIX-INVENTORY.md) must be either implemented, or listed in §18 "Dropped / replaced".
> **Code standards:** [`04-ENGINEERING-STANDARDS.md`](04-ENGINEERING-STANDARDS.md) is mandatory (DTOs, Actions/Services, thin controllers and Filament pages, universal error codes, tests).
> **Build order:** follow [`02-BUILD-PROMPTS.md`](02-BUILD-PROMPTS.md). Do not start a phase before the previous phase's tests pass.

Decisions already made by the product owner:

| Topic | Decision |
|---|---|
| Tenancy | **Multi-merchant**, one platform owner. |
| Panels | **Platform Admin panel** (owner) + **Merchant panel** (each business owner). Both Filament v5. |
| WhatsApp OTP | Keep **Baileys/BerryProtocol** in a small **Node sidecar**. |
| Google Messages SMS observer | Keep as a **Node sidecar** (headless Chrome). |
| Database | **MySQL 8**. |
| Customer-facing UI | **React** (checkout). `paykr.js` merchant SDK stays **plain JavaScript**. |

---

## 1. Target architecture

### 1.1 Processes (replaces 8 Node processes)

| Process | Replaces | Notes |
|---|---|---|
| **Laravel app** (php-fpm/Octane behind nginx) | Payment API :6100, Checkout Portal :7000, Merchant Admin :6001, Help portal :3000 | One codebase, one domain (or subdomains, see §1.2). |
| **Queue worker** (`php artisan queue:work`, Horizon recommended) | Node timers, webhook retry daemon, Gmail poller, attribution grace timers | Redis queue. |
| **Clock worker** (`php artisan paykr:clock`, long-running, 1-second loop) | `queueSyncDaemon`, `aggressivePollerDaemon`, `SESSION.PROCESSING` sweep loop, recovery engine | See §5.7. Laravel's scheduler cannot run every second; this command is required. |
| **Reverb** (`php artisan reverb:start`) | Server-Sent Events (`/checkout/:id/stream`, `/admin/stream`) | WebSockets. See §7. |
| **Scheduler** (`php artisan schedule:work` / cron) | — | Housekeeping only (prune, Gmail token refresh, health snapshots). |
| **WhatsApp sidecar** (Node) | `apps/payment-api/src/services/whatsapp-berry.service.js` | Internal HTTP on 127.0.0.1. See §14.1. |
| **Google Messages sidecar** (Node + Chrome) | `apps/sms-observer` | Internal HTTP on 127.0.0.1. Optional per merchant. See §14.2. |
| *(separate project)* Demo store | `apps/coin-wallet-store` | Not part of the gateway. Rebuild later as an example merchant integration. |
| **Dropped** | Control Center :8090, Gmail Observer :6003 (moved into Laravel jobs), Tunnel manager | See §18. |

Only nginx (443) is public. Redis, MySQL, Reverb (proxied by nginx on `/app`), and the two sidecars bind to localhost.

### 1.2 Public URLs

| Old | New |
|---|---|
| `http://host:7000/checkout/{id}` | `https://pay.<domain>/checkout/{id}` |
| `http://host:7000/paykr.js` | `https://pay.<domain>/paykr.js` (same file contract) |
| `http://host:6100/api/v1/*` | `https://pay.<domain>/api/v1/*` (**same paths**, so the Android app and merchant SDKs keep working) |
| `http://host:6001/` (merchant admin) | `https://pay.<domain>/merchant` (Filament merchant panel) |
| — (new) | `https://pay.<domain>/admin` (Filament platform panel) |
| `http://host:6003/oauth/callback` | `https://pay.<domain>/merchant/gmail/callback` |

### 1.3 Stack and versions

- PHP **8.3**, Laravel **12.x**, Filament **5.x** (needs Livewire 4, Tailwind 4), Laravel Reverb 1.x, Redis 7, MySQL 8.0+.
- React **19** + TypeScript + Vite for the checkout (`resources/checkout`), served as a standalone SPA bundle by a Blade shell. Do **not** use Inertia for the checkout iframe: it must load fast and work inside any merchant site.
- Pest for tests. Laravel Pint, Larastan level 6+.
- Money: integer **paise** everywhere (`BIGINT UNSIGNED`). Never floats. Port `toPaise`/`toRupeesString` exactly (§5.1).

---

## 2. Tenancy, roles and authentication

### 2.1 Actors

| Actor | Auth | Scope |
|---|---|---|
| **Platform admin** (`users` with `is_platform_admin = true`) | Filament `/admin` session login + mandatory TOTP 2FA | All merchants, platform settings, WhatsApp session, bans, audit. |
| **Merchant user** (`merchant_users` pivot: role `owner` \| `manager` \| `cashier` \| `developer`) | Filament `/merchant` session login (+ optional 2FA) | Only their merchant (Filament tenancy, tenant = `Merchant`). |
| **Customer** (gateway-wide identity) | WhatsApp OTP → opaque bearer token `csess_…` (stored hashed) | Their own payment sessions only. |
| **Merchant server** | **Secret API key** `sk_live_…` / `sk_test_…` (header `Authorization: Bearer sk_…`) | Create/read payments for that merchant. |
| **Merchant browser** (paykr.js) | **Publishable key** `pk_…` (header `X-Merchant-Key`, also accept legacy `X-Merchant-Id`) | Create payments only, rate-limited, origin-checked against merchant's allowed domains. |
| **Android observer phone** | Device id + HMAC signing key (per device) | Submit observations / heartbeat for its merchant. |
| **Sidecars** | Shared internal token header `X-Paykr-Internal-Token` (HMAC-derived, like today) + localhost-only | Internal callbacks. |

Role permissions (merchant panel):

| Capability | owner | manager | cashier | developer |
|---|---|---|---|---|
| Live monitor, transactions | ✓ | ✓ | ✓ | ✓ |
| Counter desk confirm/decline | ✓ | ✓ | ✓ | — |
| Branding, payment methods, UPI accounts | ✓ | ✓ | — | — |
| Webhooks, API keys, test mode, simulator | ✓ | — | — | ✓ |
| Android pairing, Gmail connection | ✓ | ✓ | — | ✓ |
| Block customer for this merchant | ✓ | ✓ | — | — |
| Team members | ✓ | — | — | — |

### 2.2 Tenancy rules (non-negotiable)

1. Every merchant-owned table has `merchant_id`; every query in merchant context is scoped (global scope + Filament tenancy). Add a Pest architecture test that fails if a merchant-owned model lacks the scope.
2. **Observations are attributed only within the receiving account they arrived on** (and therefore only within its merchant). The current code falls back to "any active session gateway-wide" (`verification-pipeline.js` step 0.3, `attribution-engine.js` "anywhere on the gateway") — **do not port those fallbacks**; with many merchants they would credit one merchant's bank SMS to another merchant's order.
3. Gmail credentials, Android devices, test mode, branding, webhooks, receiving accounts are **per merchant**. The current "sync Gmail config to all merchants" (`admin.routes.js` gmail/credentials & disconnect) must not be ported.
4. Customers are **gateway-wide** (one identity across merchants, like today). Bans:
   - **Automatic fraud ban** (§5.12) and **platform-admin ban** = platform-wide (`customers.status = BANNED`).
   - **Merchant block** = `merchant_customer_blocks(merchant_id, customer_id, reason)`: that customer cannot pay *this* merchant. Merchants cannot ban platform-wide.
5. `queue/clear` and every admin action operate on **one merchant** only (the current code clears all merchants when called for the default merchant id — do not port).

---

## 3. Data model (MySQL)

Use UUID primary keys (`char(36)`, `HasUuids`) to keep ids compatible with the existing Android app, SDKs and data export. Timestamps are UTC `timestamp(3)`.

### 3.1 Tables

Ported from `packages/db/src/sqlite-schema.sql` (+ runtime columns from `migrate.js`), with changes marked **NEW/CHANGED**.

**`users`** (NEW) — Laravel users: id, name, email (unique), password, `is_platform_admin` bool, `two_factor_secret`, `two_factor_recovery_codes`, remember_token, timestamps.

**`merchants`** — id, business_name, slug (unique), contact_email, contact_phone, `status` (ACTIVE\|SUSPENDED) **NEW**, `webhook_url`, `webhook_secret` (**encrypted cast**), `allowed_origins` JSON **NEW** (for publishable-key origin check), timestamps.
- **CHANGED:** the old `branding` JSON mixed public branding with secrets (Gmail OAuth tokens, test-mode timers, tunnel command). Split into:
  - `merchant_branding` (1:1): app_name, primary_color, header_theme, logo_url (stored file path, uploaded via Filament), sidebar_notice, subbanner_text, trust_badge_text, hide_trust_badge, hide_subbanner, hide_powered_by, hide_time_saving_banner, checkout_notice, footer_guarantee, counter_instructions, card_counter_instructions, terms_url, privacy_policy_url, support_emails JSON, support_mobiles JSON.
  - `merchant_payment_settings` (1:1): selling_mode (ONLINE\|OFFLINE), cod_enabled, cash_enabled, card_enabled, enabled_payment_methods JSON (derived, see §5.14).
  - `merchant_gmail_connections` (1:1): client_id, client_secret (**encrypted**), refresh_token (**encrypted**), access_token (**encrypted**), token_expires_at, connected_email, last_tested_at, last_successful_fetch, last_observation_at, last_history_id, status.
  - `merchant_test_modes`: enabled_at, expires_at, enabled_by (user id).
- Defaults when a value is null are listed in §10.6.

**`merchant_users`** (NEW) — merchant_id, user_id, role, timestamps; unique(merchant_id, user_id).

**`merchant_api_keys`** (NEW) — merchant_id, type (publishable\|secret), mode (live\|test), prefix (e.g. `pk_live_ab12`), key_hash (sha256), last_used_at, revoked_at.

**`payment_receiving_accounts`** — id, merchant_id, upi_id, payee_name, is_merchant_enabled, is_active, is_slot_busy, current_active_session_id, timestamps.
- Invariant "max one active VPA per merchant": MySQL has no partial index → add `active_marker TINYINT NULL` generated as `IF(is_active, 1, NULL)` (stored) and `UNIQUE(merchant_id, active_marker)`.

**`customers`** — id, name, email (unique), mobile_number (unique, canonical 10 digits), password_hash (nullable), is_phone_verified, status (ACTIVE\|BANNED), ban_reason, banned_at, unbanned_at, last_device, last_login_at, timestamps.
- **CHANGED:** OTP no longer stored on the customer row → `customer_otp_challenges` (below).

**`customer_otp_challenges`** (NEW, replaces `customers.otp_code` and the in-memory `pendingOtpChallenges` Map) — id, mobile_number, code_hash, purpose (LOGIN\|SIGNUP), pending_name, pending_email, attempts, expires_at, consumed_at, ip_address, created_at.

**`customer_sessions`** — id, customer_id, token_hash (unique; store sha256, never the raw `csess_` token), expires_at (30 days), is_active, ip_address, user_agent, timestamps.

**`payment_sessions`** — id, merchant_id, customer_id, receiving_account_id, order_id, amount_paise (BIGINT, > 0), currency, method (UPI\|COD\|CASH_COUNTER\|CARD_COUNTER), status (§5.2), queue_position, slot_acquired_at, expires_at, processing_started_at, processing_expires_at, completed_at, return_url, attributed_observation_id, idempotency_key, device_id, verification_level, verification_sources JSON, verification_tag, metadata JSON, `is_test` bool **NEW**, timestamps. `UNIQUE(merchant_id, order_id)`; index (receiving_account_id, status); index (customer_id, status); index (device_id).

**`payment_observations`** — id, receiving_account_id, source, amount_paise, observed_at, ingested_at, sender_vpa, bank_reference_number, fingerprint_hash, raw_payload JSON, attribution_status, attributed_payment_id, created_at. Index (receiving_account_id, amount_paise, attribution_status); index (fingerprint_hash); index (bank_reference_number).

**`android_devices`** — id, merchant_id, device_name, device_model, `signing_key` (**encrypted**; the old column `signing_key_hash` actually stored the raw key), is_active, last_heartbeat_at, last_observation_at, created_at.

**`observer_pairing_tokens`** (NEW, replaces the in-memory pairing Map) — token_hash, merchant_id, created_by, expires_at (10 min), consumed_at, consumed_by_device_id.

**`webhook_deliveries`** — id (= `X-Gateway-Delivery-Id`), merchant_id, payment_id, event_type, payload (exact signed string), endpoint_url, response_status, response_body (≤1000 chars), attempt_count, is_successful, next_retry_at, created_at.

**`support_tickets`**, **`ticket_messages`** — as today (merchant_id, customer_id, payment_id, subject, status OPEN\|IN_PROGRESS\|RESOLVED\|CLOSED; messages sender_type CUSTOMER\|ADMIN, sender_id, message, attachments JSON).

**`audit_logs`** — as today + `actor_id` now references a real user/customer/device id; never the literal `'ADMIN_USER'`.

**`security_events`** — as today (forensic evidence; raw_message verbatim).

**`devices`** (browser device identity) — as today (public_key, fingerprint_hash, device_token (store hash), status…).

**`merchant_customer_blocks`** (NEW) — merchant_id, customer_id, reason, blocked_by, timestamps; unique(merchant_id, customer_id).

**`whatsapp_sessions`** (NEW, platform) — status, phone_number, last_connected_at, last_error. (Credentials stay inside the sidecar's own auth folder.)

### 3.2 Seeds

- Platform admin from `PAYKR_ADMIN_EMAIL` / `PAYKR_ADMIN_PASSWORD` (refuse to seed in production if the password is a template value — same rule as `packages/common/src/admin-auth.js`).
- **Local/testing only:** demo merchant "Paykr Demo Store" (`00000000-0000-0000-0000-000000000001`), receiving account `merchant@upi` (`…0002`), webhook `http://localhost:5000/api/webhook`, secret from env. Never seed customers.

---

## 4. Glossary of statuses

- **Payment status:** `INITIATED, QUEUED, ACTIVE, PROCESSING, SUCCESS, FAILED, EXPIRED, CANCELLED`. Public outcome = the four terminal ones.
- **Observation attribution:** `UNATTRIBUTED, ATTRIBUTED, LATE_UNMATCHED, DUPLICATE_IGNORED`.
- **Observer sources:** `GMAIL, GOOGLE_MESSAGES, ANDROID_OBSERVER, SMS_GATEWAY, MANUAL_COUNTER`.
- **Verification tag:** `UNVERIFIED`, `Single Verification (<SRC>)`, `Dual Verification (<SRC> + <SRC>)`, `COD_ORDER_PLACED`.
- **UPI health tier:** `GREEN` (WORKING) / `YELLOW` (BUSY) / `RED` (ISSUES).

---

## 5. Domain rules (port exactly; fixes are marked FIX)

Implement each as a service class under `app/Domain/...` with Pest unit tests. Names are suggestions.

### 5.1 Money & validation (`packages/common/src/money.js`, `contracts.js`, `upi.js`)
- `Money::toPaise(string|int|float)`: must match `^\d+(\.\d{1,2})?$`, result > 0, exact integer math (no floats). `toRupeesString(paise)` → `"499.00"`. `formatINR` → `₹499.00`.
- `normalizeMobile`: strip non-digits, keep last 10. Valid if `^[6-9]\d{9}$`.
- `normalizeEmail`: trim + lowercase; valid if `^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$`. Missing email defaults to `<mobile>@customer.paykr.local`.
- Password (only for the legacy password login): ≥ 6 chars. Use Laravel `Hash` (bcrypt/argon2); migrate old PBKDF2 hashes (`iterations:salt:hash`, sha512, 64 bytes) by verifying them once and rehashing.
- VPA: `^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z0-9.\-_]{2,64}$`.
- UPI URI: `upi://pay?pa=<vpa>&pn=<payee>&am=<rupees>&tn=<orderId>` (URLSearchParams encoding). Android intent variant: `intent://pay?<query>#Intent;scheme=upi;package=<pkg>;end`.
- UPI provider registry: port the 30 entries of `packages/common/src/upi-providers.js` (id, name, shortName, packageName, brandColor, keywords, enabled) as a config file; serve icons from `public/icons`.

### 5.2 State machine (`packages/core/src/state-machine.js`)
Allowed transitions: `INITIATED→ACTIVE|QUEUED|CANCELLED`, `QUEUED→ACTIVE|CANCELLED`, `ACTIVE→PROCESSING|EXPIRED|CANCELLED` (+ `SUCCESS` for COD/counter confirmation), `PROCESSING→SUCCESS|FAILED` (+ `CANCELLED` by customer). Terminal states are **final**.

All status changes go through **one** `PaymentTransitionService` that, inside a DB transaction with `SELECT … FOR UPDATE` on the session row:
1. checks the transition is allowed (terminal → anything is rejected and returns `closed`),
2. writes status + timestamps,
3. releases the receiving-account slot when leaving ACTIVE/PROCESSING (`is_slot_busy = 0, current_active_session_id = NULL` only if it pointed at this session),
4. writes the audit log,
5. dispatches **exactly one** domain event (`PaymentSucceeded`, `PaymentFailed`, `PaymentExpired`, `PaymentCancelled`) **after commit**.

Listeners of terminal events (after commit): broadcast to the checkout channel, queue the merchant webhook, promote the next queued customer (§5.6).
**FIX:** today several paths (daemon expiry, `/payments` stale expiry, `queue/clear`, recovery) change status without sending the webhook, and counter-decline could mark an already-paid session FAILED. With a single transition service every terminal change sends exactly one webhook, and closed sessions can never change again.

### 5.3 Create payment — `POST /api/v1/payments` (`payments.routes.js`)
Auth: secret key (server) or publishable key (browser). Body: `orderId`*, `amount`* (rupees string/number), `currency` (INR), `method` (default UPI), `customer{name,email,mobileNumber|phoneNumber}`, `customerId`, `metadata`, `returnUrl`, `deviceId` (or `X-Device-Id`), `Idempotency-Key` header.
1. Merchant must be ACTIVE. (FIX: no "default merchant" fallback outside local env.)
2. Device id given and unknown → register a placeholder device (`public_key = adhoc_<id>`).
3. Existing session with same (merchant, orderId):
   - SUCCESS → 200 with existing data (already paid).
   - ACTIVE past `expires_at` / PROCESSING past `processing_expires_at` → expire it (via transition service), promote queue, continue.
   - Non-terminal and request device ≠ bound device → **409** `DUPLICATE_SESSION_DETECTED`, `code: DEVICE_SESSION_MISMATCH`, `action: END_SESSION_AND_CREATE_NEW`.
   - Non-terminal same device → 200 `activeSessionDetected: true` with existing checkoutUrl.
   - Terminal (FAILED/EXPIRED/CANCELLED) → create a **new** session with orderId `<orderId without -R####>-R<4 random digits>`.
4. Amount → paise (400 on invalid).
5. UPI: merchant must have an active receiving account (else **422** "No active UPI receiving account"; today 500). Snapshot `{id, upiId, payeeName, isMerchantEnabled}` into `metadata.receivingAccountSnapshot`. Counter methods (CASH_COUNTER/CARD_COUNTER) don't need an account.
6. Resolve customer from `customerId` or customer email/mobile; banned → **403** `status: BLOCKED`. Merchant-blocked → 403 too.
7. Customer has another live session on a different device → 409 as above.
8. Status: counter methods start **ACTIVE**; UPI starts **INITIATED** (no slot, no timer) until the customer signs in at checkout. (Drop the `autoActivate` test flag; tests use factories instead.)
9. Response **201** `{paymentId, orderId, amount, amountPaise, currency, status, queuePosition, checkoutUrl}`; checkoutUrl = `<APP_URL>/checkout/<id>`.

`GET /api/v1/payments/{id}` (secret key, own merchant only — FIX: currently public): `{paymentId, orderId, amount, amountPaise, currency, status (public outcome if terminal), internalStatus, method, verificationLevel, verificationSources, verificationTag, utr, completedAt, returnUrl, metadata}`.
`GET /api/v1/payments/{id}/cod`, `GET /api/v1/orders/{orderId}/cod`: COD info incl. customer `{id,name,email,mobileNumber}`, `isCod`, `placedAt` (secret key, own merchant).

### 5.4 Customer identity & WhatsApp OTP (`customer.routes.js`, `customer.repository.js`)
- `POST /api/v1/customer/check-user {mobileNumber}` → `{exists, mobileNumber}`. **FIX:** do not return name/email (account enumeration). Return only `exists` and, if it exists, a masked name (`R***** S*****`).
- `POST /api/v1/customer/send-otp {mobileNumber, isNewUser, name, email}`:
  - New user (`isNewUser` and unknown mobile): name required, email required + valid + unique (409 if taken).
  - Generate a 6-digit OTP with `random_int` (FIX: today `Math.random`). Store a hash in `customer_otp_challenges`, 5-minute expiry.
  - Send via WhatsApp sidecar (§14.1) with the template from `sendOtp()` (merchant/app name white-labelled). Sidecar not connected → still 200 with `dispatch.delivered=false, queued=true` (same as today).
  - Return `devOtp` **only** when `APP_ENV=local|testing`.
  - FIX: rate limit — max 3 sends per mobile per 10 min, 10 per IP per hour; 30-second resend cooldown (UI already enforces 30 s).
- `POST /api/v1/customer/verify-otp {mobileNumber, otp, name?, email?}`:
  - Max 5 attempts per challenge (FIX), then challenge locked.
  - Existing customer: verify, clear challenge, `is_phone_verified=1`, `last_login_at=now`.
  - New customer: create from pending name/email (fallback name `Customer <last4>`).
  - Banned → 403 `{error: 'ACCESS BLOCKED', isBanned: true, message: …}` (message text in §10.5).
  - Issue customer session (30 days): token `csess_<64 hex>`, store hash; set cookie `paykr_session` (HttpOnly, SameSite=Lax, Secure in prod) **and** return `token` (checkout iframe uses `Authorization: Bearer`).
  - Response `{success, token, customerId, phoneNumber, fullName, customer{…}}`.
- `POST /api/v1/customer/register`, `POST /api/v1/customer/login` (password based; keep for API parity, not used by the checkout UI). Same validations as today.
- `GET /api/v1/customer/me`, `POST /api/v1/customer/logout`, `POST /api/v1/customer/switch-account {token}` (validates a saved token and re-issues cookie).
- Decommissioned in the current code and **not ported** (they return 404 today): `GET /customer/payments`, `POST /customer/tickets`, `GET|POST /customer/tickets/{id}/messages`, `GET /customer/tickets/{id}/stream`.

### 5.5 Checkout session access & device binding (`checkout.routes.js` `checkSessionDeviceAccess`, `device-security.js`)
- Browser device identity: checkout generates an ECDSA P-256 key pair (WebCrypto) and fingerprint signals (UA, platform, language, timezone, screen `WxHxDepth`, hardwareConcurrency, deviceMemory, WebGL renderer, canvas signature), then `POST /api/v1/devices/register {publicKey, signals, fingerprintHash?}` → `{deviceId, deviceToken, fingerprintHash, status, isExisting}`. Fingerprint = sha256 of JSON with sorted keys of the normalized signals (port `normalizeDeviceSignals` exactly). Existing public key → returns existing device. Stored in localStorage `ypg_device_id`, `ypg_device_token`.
- Every checkout call sends `X-Device-Id` and `X-Device-Token`.
- Access rule: terminal sessions always readable. Unbound session + device id → bind (create placeholder device if needed). Bound session: allowed if same device id; **FIX:** also require the matching `X-Device-Token` (today a missing device id is allowed). Mismatch → **409** `{error: 'DUPLICATE_SESSION_DETECTED', session_id, conflicting_session_id, action: 'END_SESSION_AND_CREATE_NEW', message, boundDeviceId, requestingDeviceId}`.
- **FIX:** remove the `X-Internal-Render: true` bypass (anyone can send it). Server-side rendering of the checkout shell must call the service layer directly, not HTTP.
- `POST /api/v1/devices/verify {deviceId, deviceToken?, payload?, signature?}` → `{valid, tokenValid, signatureValid}` (ECDSA verify: accept IEEE-P1363 64-byte and DER signatures). `GET /api/v1/devices/{id}`, `GET /api/v1/devices/customer/{customerId}`: **FIX** restrict to the owning customer token or platform admin (today public).

### 5.6 Payment slot queue (`queue-engine.js`, `payment-session.repository.js acquireSlotAtomic`)
Invariant: **at most one ACTIVE or PROCESSING UPI session per receiving account.**
- `enqueueOrActivate(session, account)` in one transaction with `SELECT … FOR UPDATE` on the `payment_receiving_accounts` row:
  1. Find the account's ACTIVE/PROCESSING session; if it's stale (past its expiry) terminate it (EXPIRED for ACTIVE, FAILED for PROCESSING) through the transition service.
  2. If the owner is this same session → return ACTIVE unchanged (never demote).
  3. Slot free → session ACTIVE, `slot_acquired_at=now`, `expires_at=now+PAYMENT_ACTIVE_WINDOW (300 s)`, queue_position 0; account busy with `current_active_session_id`. Audit `PAYMENT_SLOT_ACTIVATED`, event `SessionActivated`.
  4. Slot busy → QUEUED with `queue_position = max(queued)+1` (keep an existing positive position). Audit `PAYMENT_ENQUEUED`.
- `promoteNext(account)`: if slot still owned → nothing; else oldest QUEUED (`queue_position ASC, created_at ASC`) → activate, reindex remaining queue 1..n, audit `QUEUE_PROMOTION_TO_ACTIVE`, broadcast `status_change {status: ACTIVE, queuePosition: 0, expiresAt}` to that session and `queue_update {promotedId}` to the account's queue channel.
- Estimated wait for QUEUED = remaining seconds of the current slot owner + `(position-1) × 330` s.
- COD and counter payments never hold a UPI slot.

### 5.7 Timers and the clock worker (`timing-engine.js`, `recovery-engine.js`, `payment-api/src/index.js` daemons)
Node keeps timers in memory. Laravel must be crash-safe:
- On activation dispatch `ExpireActivePayment($id)` delayed to `expires_at`; on "Payment Done" dispatch `FailProcessingPayment($id)` delayed to `processing_expires_at`. Jobs re-check status before acting (idempotent).
- `php artisan paykr:clock` (long-running, 1-second tick, single instance via cache lock) replaces the three Node daemons:
  1. Expire ACTIVE sessions with `expires_at <= now`; fail PROCESSING sessions with `processing_expires_at <= now` (safety net for lost jobs; through the transition service).
  2. For every active receiving account with a free slot and waiting queue → `promoteNext`.
  3. For every merchant with an ACTIVE/PROCESSING UPI session → request an immediate observer sweep: dispatch `PollGmailNow(merchant)` (throttled to once per 1.5 s per merchant) and call the Google Messages sidecar `POST /sweep` (fire-and-forget, 800 ms timeout).
  4. Re-run attribution for that account's `UNATTRIBUTED` observations.
- On boot (`paykr:clock` start) run recovery (overdue PROCESSING→FAILED, overdue ACTIVE→EXPIRED, re-dispatch jobs for live sessions, promote queues) and log counts `{expiredCount, failedCount, rescheduledCount, promotedCount}` (exposed on `/health`).
- Configurable: `PAYMENT_ACTIVE_WINDOW_SECONDS=300`, `PAYMENT_PROCESSING_WINDOW_SECONDS=30`, `PAYMENT_GRACE_PERIOD_MS=5000`, `LATE_OBSERVATION_WINDOW_MINUTES=30`.

### 5.8 Checkout actions (`checkout.routes.js`)
All require device access (§5.5) unless noted.

| Endpoint | Rules |
|---|---|
| `GET /api/v1/checkout/{id}/state` | Banned customer or blocked session → `{status: BLOCKED, isBanned, fraudDetected, customerName, blockedNotice{title, subtitle, message, investigating, reason}}` (texts §10.5). Enforce expiry on read (ACTIVE past expiry → EXPIRED; PROCESSING past → FAILED). Terminal → `{id, paymentId, orderId, amount, amountPaise, currency, status, method, utr, merchantName, timeRemainingSeconds: 0, isTerminal: true, branding}`. Otherwise full state: `paymentId, orderId, merchantName, amount, amountPaise, currency, status, reason/cancelledReason, method, verificationLevel/Sources/Tag, sellingMode, enabledMethods, customer{id,name,email,mobileNumber}, hasActiveSessionConflict, conflictingSession{id,paymentId,orderId,amount,status,checkoutUrl}, queuePosition, timeRemainingSeconds, expiresAt, serverNow, upiUri (only when ACTIVE), payeeVpa, payeeName, isMerchantEnabled, upiMode (MERCHANT\|NON_MERCHANT), upiServerIndicator (§5.15), receivingAccountId, providers (UPI registry), canClickPaymentDone, returnUrl, supportEmails, supportMobiles, privacyPolicyUrl, deviceId, branding`. Same-device conflict with another live session of this customer/device → `hasActiveSessionConflict: true` (UI offers continue or end-and-restart); different device → 409. **FIX:** `branding` is an explicit allow-list of public fields (§10.6); today the whole merchant JSON is spread, which would expose Gmail tokens and test-mode internals. Same for `metadata` (strip `receivingAccountSnapshot` internals if needed). |
| `GET /api/v1/checkout/server-health?merchantId=` | UPI health for that merchant (§5.15). |
| `POST /api/v1/checkout/{id}/bind-customer` | **FIX:** requires customer bearer token (today a bare `customerId` in the body is accepted → anyone could bind any customer). Session already bound to a different customer → 409 (immutable identity). Other live session for this customer on a different device → 409; same device → conflict flag, no activation. Else bind; if INITIATED → `enqueueOrActivate` on the merchant's active account, broadcast `status_change`, admin feed `status_change`, audit `CUSTOMER_AUTHENTICATED_AND_BOUND`. |
| `POST /api/v1/checkout/{id}/retry-new-payment` | Cancel all other ACTIVE/PROCESSING/QUEUED sessions of the same customer or device (`reason USER_RETRY_NEW_PAYMENT`, `replacedBySessionId`), then activate this session on the merchant's current active account. |
| `POST /api/v1/checkout/{id}/payment-done` | PROCESSING → idempotent 200. ACTIVE past expiry → EXPIRED + 400. Not ACTIVE → 400. Else ACTIVE→PROCESSING (`processing_expires_at = now+30 s`), audit `CUSTOMER_CLICKED_PAYMENT_DONE`, start fast sweeps (§5.7), broadcast. |
| `POST /api/v1/checkout/{id}/cancel` | **FIX:** require device access (today anyone with the id can cancel). Terminal → 200 current status. Else CANCELLED (audit `PAYMENT_CANCELLED_BY_USER`), promote queue. |
| `POST /api/v1/checkout/{id}/select-method {method}` | Method must be in the merchant's enabled list (§5.14). UPI→COD: release slot, status ACTIVE with its own 5-minute expiry (no queue). UPI→counter: release slot, ACTIVE, no expiry, notify counter desk (`counter_payment_requested`). Back to UPI: `enqueueOrActivate`. Promote/reindex the freed account. Audit `PAYMENT_METHOD_SWITCHED`, event `PaymentMethodSwitched`. Returns counter instructions text. |
| `POST /api/v1/checkout/{id}/confirm-cod` | Only ONLINE mode with COD enabled. Atomically → SUCCESS, method COD, tag `COD_ORDER_PLACED`, level 1, release slot, promote, audit `COD_ORDER_PLACED`, webhook SUCCESS. Closed session → 409. |
| `GET /api/v1/checkout/{id}/cod` | COD info. |
| `POST /api/v1/checkout/{id}/expire` | Client hint only. **FIX:** expire only if `expires_at <= now + 2 s` (today it expires immediately on request). |
| `POST /api/v1/checkout/{id}/trigger-hook {action, details}` | Audit `HOOK_<action>` (actor CUSTOMER), event `PaymentActionTriggered`. Rate-limit; action must match `^[A-Z_]{2,40}$`. |
| `POST /api/v1/checkout/{id}/simulate-observation` | **Platform admin or merchant developer only, and only when that merchant's test mode is active.** Runs the pipeline (§5.10) with `isTest`. |
| `POST /api/v1/checkout/{id}/end-and-restart-payment` (alias `end-session-and-create-new`) | Requires device id. Cancels (reason `REPLACED_ON_ANOTHER_DEVICE`, `replacedByDeviceId`) the target, the conflicting session, all other live sessions of the customer on other devices, and the account's active session if it's the same base order or customer; releases slots; promotes; creates a new INITIATED→activated session with orderId `<base>-R####`, `metadata.reboundFromSessionId`, bound to the new device; audit `PAYMENT_SESSION_RECREATED_ON_NEW_DEVICE`; 201 with `newSessionId, checkoutUrl, terminatedSessionIds`. |
| `GET /api/v1/checkout/{id}/stream`, `/events` | Replaced by Reverb channel `payment.{id}` (§7). Keep the two paths returning 410 with a hint, or keep an SSE fallback if any integrator depends on it. |

### 5.9 Observation ingestion & normalization (`normalizer.js`, `observation.js`, `bank-sms-parser.js`, `bank-email-parser.js`)
- Sources: Android app (`/api/v1/observers/sms`, `/api/v1/pollers/android/events`), Google Messages sidecar (internal endpoint), Gmail job (in-process).
- Amount extraction (`extractIntelligentAmount`), in order: currency prefix `(rs.?|inr)\b|₹` + number; number + currency suffix; number near credit words (`credited|received|deposit|payment|paid|transferred|upi|cr`, value 0 < x < 10,000,000); standalone `\d{1,6}\.\d{2}`. Commas removed.
- SMS parser: requires a credit word; ignores pure debits (`debited|withdrawn|sent rs|paid rs` without `credited|received|deposit`). UTR patterns, in order: `(upi ref(no)|utr(no)|rrn(no)|txn(id|ref)|ref no)[\s:=-]+([A-Za-z0-9]{6,25})`, `ref(no)[:#\s]?([A-Za-z0-9]{8,22})`, `\b(\d{12})\b`. Sender VPA: `(from|by|vpa)\s+(<vpa>)`.
- Email parser: subject + body; same credit/debit rule (also accepts `credit`); UTR from `(utr|ref(no)|txn(id)|rrn)[:\s#]+(...)`, else `UPI/CR/<ref>`, else 12 digits.
- Fingerprint: `sha256("<receivingAccountId>:<amountPaise>:<entropy>")` where entropy = `REF:<UTR uppercased>` if a UTR exists, else `TIME_BUCKET:<floor(epochMs/60000)>:<source>`.
- Port all regexes verbatim and add the existing parser tests (Appendix A8) as Pest datasets.

### 5.10 Verification pipeline (`verification-pipeline.js`) — 6 stages, **authoritative**
Input `{source, rawText, sender, receivedAt, receivingAccountId, paymentSessionId?, customerId?, isTest, testModeActive, suppressBan, deviceInfo, isSimulated, messageId}`. Output `{success, status: VERIFIED|REJECTED|SUSPICIOUS|UNRESOLVED, source, messageId, receivedAt, amount, amountPaise, reference, sender, isTest, raw, failingStage, failingCondition, reason, details, stages{stage1..stage6}, session{id, orderId, amountPaise, status}}` — keep this shape: the simulator UI renders it.

0. **Session context:** `paymentSessionId` if given (must belong to the account's merchant); else the account's current ACTIVE/PROCESSING session. **FIX:** no merchant-wide or gateway-wide fallbacks (§2.2). Customer = session customer, else `customerId`, else lookup by sender identifier.
   Attributable-to-customer: sender's last 10 digits = customer mobile, or sender email contains customer email, or `submissionIdentity` matches.
1. **Load:** source = `GMAIL` if it contains GMAIL/EMAIL else `SMS`; message id `OBS-xxxx`/`TEST-xxxx`; default sender `alerts@retail.bank.in` (Gmail) / `BANK-ALERT` (SMS); preserve raw text verbatim.
2. **Filter:**
   - SMS: purely numeric sender (personal number) → REJECT `PERSONAL_SMS_REJECTED` unless attributable to the customer (then continue flagged). Otherwise sender must contain a letter and match `^[A-Za-z0-9_\-.]+$`, else REJECT `INVALID_SMS_SENDER`.
   - Gmail: domain must end with `.bank.in` (or be `bank.in`); in local/testing also `TEST`, `.paykr.local`, `hdfcbank.net`. Else REJECT `INVALID_EMAIL_DOMAIN` unless attributable to customer.
   - Other → REJECT `IRRELEVANT_SOURCE`.
3. **Parse:** must have a credit word `(credited|credit|cr\b|deposit|deposited|received|transferred)` → else `MISSING_CREDIT_INDICATOR`; currency `rs|inr|₹` → else `MISSING_CURRENCY_INDICATOR`; a **12-digit** UTR → else `MISSING_12_DIGIT_REFERENCE`; amount → else `INVALID_OR_MISSING_AMOUNT`.
4. **Verify:** no session → UNRESOLVED `NO_ACTIVE_SESSION`; `receivedAt < slot_acquired_at (or created_at) − 1 s` → `BEFORE_SESSION_CREATION`; `receivedAt > expires_at` → `SESSION_EXPIRED`; amount ≠ session amount → `AMOUNT_MISMATCH` (message shows both rupee values); same fingerprint already stored from the same source → `DUPLICATE_OBSERVATION`; UTR already attributed to another SUCCESS session → `REFERENCE_ALREADY_USED`.
5. **Security:** customer banned → `BANNED_IDENTITY`. Fraud = all six conditions (session ACTIVE/PROCESSING; session has a customer; observation attributable to that customer; message pretends to be a credit; not a legitimate bank sender (sender invalid OR attributable to customer); targets that customer's session). Then:
   - write `security_events` (event `FRAUDULENT_PAYMENT_INJECTION`, full evidence: raw message verbatim, extracted amount/UTR, parser + verification results, device info, metadata `{orderId, testModeActive, autoBanApplied, banSuppressed}`);
   - if `suppressBan` (test mode only): stage5 `SUSPICIOUS_FLAGGED`, audit `TEST_MODE_FRAUD_FLAGGED_BAN_SUPPRESSED`, stage6 `FLAGGED_SUSPICIOUS`, final **SUSPICIOUS** (never pay);
   - else: ban customer platform-wide (`FRAUDULENT_PAYMENT_INJECTION`, expire all their live sessions, release slots), audit `CUSTOMER_AUTOMATICALLY_BANNED`, broadcast `status_change {status: BLOCKED, …blockedNotice}` + `account_blocked`, event `PaymentFraudBlocked`, final **REJECTED** `FRAUDULENT_PAYMENT_INJECTION` (HTTP 403 to the submitter).
6. **Attribute:** normalize and hand to the attribution engine (§5.11). VERIFIED if attributed, else UNRESOLVED.

**FIX (critical):** the current `/pollers/events` and `/observers/sms` handlers, when the pipeline returns REJECTED for a non-fraud reason, fall through to the attribution engine directly. That means a message the pipeline rejected (personal sender, wrong amount, no UTR) can still confirm a payment. In Laravel the pipeline result is final: REJECTED/UNRESOLVED/SUSPICIOUS never reach attribution. The only path to attribution is stage 6.

The unused `FRAUD_PATTERNS` list in the current file is dead code — do not port.

### 5.11 Attribution engine (`attribution-engine.js`)
Input: normalized observation for a receiving account.
1. Same fingerprint already stored:
   - same source → `DUPLICATE_IGNORED` (audit `DUPLICATE_OBSERVATION_DROPPED`);
   - different source and the stored one is attributed → store, add verification source to that session (finalize), cancel pending grace timer, audit `PAYMENT_VERIFICATION_UPGRADED`, `PaymentSucceeded` only if this was the first success, event `PaymentVerificationUpgraded`.
2. Store observation as UNATTRIBUTED.
3. Candidate session: `rawPayload.targetSessionId` if amount matches, else the account's current ACTIVE/PROCESSING session, else an ACTIVE/PROCESSING session **on the same account** with the exact amount. (**FIX:** never search other accounts/merchants.)
4. Eligible when status ACTIVE/PROCESSING, amount exact, observed time ≥ (slot_acquired_at − 300 s) and not belonging to a recently expired/failed same-amount session on this account that started before the slot.
   - Pending grace for this session exists → second channel within the window → finalize **Level 2** (`Dual Verification (A + B)`), audit `PAYMENT_VERIFICATION_UPGRADED_L2`.
   - Otherwise record **Level 1** without finalizing (`PAYMENT_LEVEL1_VERIFIED`, broadcast `status_change {status: VERIFYING, verificationLevel: 1}` with text "Payment detected! Verifying secondary channel..."), mark observation ATTRIBUTED, dispatch `FinalizeLevel1Payment` delayed by `PAYMENT_GRACE_PERIOD_MS` (5 s; 0 in tests = immediate). When it runs: finalize to SUCCESS (`PAYMENT_ATTRIBUTED_SUCCESS_L1`) **only if the session is still ACTIVE/PROCESSING**.
5. Observation matches a session that went SUCCESS in the last 60 s on this account (same amount, no conflicting UTR, observed within that session's window ± 30 s) → upgrade verification level (no second success, no second webhook).
6. Observation matches an EXPIRED/FAILED/CANCELLED session on this account within `LATE_OBSERVATION_WINDOW` (30 min) with the same amount → **late payment**: mark `LATE_UNMATCHED`, audit `LATE_OBSERVATION_DETECTED`, open a support ticket for the merchant ("A payment of ₹X was received after order #… had already ended (STATUS). The order was NOT marked paid. Please refund the customer, or fulfil the order manually if you confirm the money arrived."), event `PaymentLateDetected`. The session stays closed. *(This is the fix made in the Node code on 2026-10-18 — keep it.)*
7. Otherwise UNATTRIBUTED (kept for later matching by the clock worker).

`recordVerificationSource(session, source, observationId, finalize)`: sources set → level = min(2, count); tag `Single Verification (X)` / `Dual Verification (X + Y)` (GOOGLE_MESSAGES shown as SMS, GMAIL as Gmail); finalizing a live session → SUCCESS via the transition service; closed session → returns `closed`, changes nothing.

### 5.12 Fraud, bans and security console (`customer.repository.js`, admin security routes)
- Ban (`banCustomer`): status BANNED, `ban_reason` (`FRAUDULENT_PAYMENT_INJECTION` \| `MANUAL_BAN`), `banned_at`, `last_device`; expire every INITIATED/QUEUED/ACTIVE/PROCESSING session of that customer and release their slots (through the transition service — FIX: today raw SQL without webhooks).
- Unban: ACTIVE, clear reason, `unbanned_at`; audit `USER_UNBANNED` with previous/new status and admin identity; security event `USER_UNBANNED` (`RESTORED_BY_ADMIN`).
- Manual ban: audit `CUSTOMER_MANUALLY_BANNED` (reason, notes), security event `MANUAL_BAN`.
- Banned list with search (name/email/mobile) joined with the latest security event and related session (`findAllBanned`).
- Ban evidence package (`getBanEvidence`): customer summary (total orders, total spent), ban type (MANUAL/AUTOMATIC), trigger event, session, raw evidence verbatim, observation metadata, discrepancy analysis, timeline, history. Port the shape; the Filament "View evidence" page renders it.
- Platform admin: all of the above, platform-wide. Merchant: can view security events for their own sessions and use merchant blocks (§2.2), not platform bans.

### 5.13 Merchant webhooks (`webhook-engine.js`)
- Events: `SUCCESS`, `FAILED`, `EXPIRED`, `CANCELLED` — one per terminal transition (§5.2).
- Payload (exact key order, serialized once, stored verbatim and re-sent verbatim on retry):
  `{"event","paymentId","orderId","amount","amountPaise","currency","method","utr","completedAt","metadata"}`.
- Headers: `Content-Type: application/json`, `X-Gateway-Event`, `X-Gateway-Signature: sha256=<hex HMAC-SHA256(payload, webhook_secret)>`, `X-Gateway-Delivery-Id: <uuid>`, `X-Gateway-Timestamp: <ms>`.
- Timeout 10 s. Success = 2xx. Store response status + first 1000 chars of body.
- Retries: `DeliverWebhook` job with backoff `[5 s, 30 s, 2 m, 10 m, 1 h]`, up to `WEBHOOK_MAX_RETRIES` (5) attempts total; same delivery id on every retry. *(The Node `retryPending()` was never scheduled until the 2026-10-18 fix.)*
- **FIX:** block webhook URLs that resolve to private/loopback/link-local ranges in production (SSRF), require https in production.
- Merchant panel: list deliveries, view payload/response, "Resend" action, "Send test webhook".
- Keep `AGENTS.md` §4 verification examples valid (raw-body HMAC).

### 5.14 Selling modes & payment methods (`resolveSellingModeAndMethods`)
- ONLINE: UPI (always on) + optional COD. OFFLINE: UPI + optional CASH_COUNTER + optional CARD_COUNTER.
- `enabled_payment_methods` is derived: `[UPI] + (ONLINE ? [COD if cod_enabled] : [CASH if cash_enabled, CARD if card_enabled])`. UPI can never be disabled.
- Inference rules when mode is missing are only for legacy data import; the Filament form always saves an explicit mode.
- Counter desk: pending counter payments = ACTIVE sessions with method CASH_COUNTER/CARD_COUNTER for the merchant (oldest first, with customer name/mobile). Confirm → SUCCESS (`COUNTER_MANUAL_PAYMENT_CONFIRMED`, actor = cashier user id, method). Decline → FAILED (`COUNTER_MANUAL_PAYMENT_DECLINED`, reason). Both reject closed sessions with 409 (FIX for decline). Real-time: counter desk subscribes to `merchant.{id}.live`.

### 5.15 UPI health indicator (`checkUpiServerHealth`)
Per merchant:
- SMS healthy: an active Android device of this merchant with heartbeat < 5 min, **or** the Google Messages sidecar reports this merchant's profile `paired && CONNECTED && BACKGROUND && (polling || lastSeen < 5 min)`.
- Gmail healthy: merchant has a refresh token **and** the last successful Gmail poll is < 5 min old (FIX: today "has refresh token" alone counts as healthy).
- GREEN both, YELLOW one (`warning: 'Your payment may go through extra processing seconds.'`), RED none (`warning: 'It is not recommended to pay by UPI right now as the server is facing downtime. We recommend paying by cash instead.'`, `recommendedAlternative: 'CASH'` if COD (online) or CASH_COUNTER (offline) is enabled).
- Labels: `Server Is Working` / `UPI Server Busy` / `Server Facing Issues`.

### 5.16 Test mode & payment simulator (`admin.routes.js` dev/*)
- Test mode per merchant: enable → `expires_at = now + 10 min`; audit `TEST_MODE_ENABLED|DISABLED` with timestamps and `autoBanDisabled`.
- Test-mode inject (`dev/test-mode/inject`) and simulator inject (`dev/simulator/inject`): **403** unless test mode active. Inject runs the **real** pipeline with `isTest, testModeActive, isSimulated, suppressBan` (optional), source SMS\|GMAIL, free-form sender, content, receivedAt, optional target session (must be ACTIVE/PROCESSING, else 400 `SESSION_NOT_ACTIVE`). Audit `TEST_OBSERVATION_INJECTED` / `SIMULATED_OBSERVATION_INJECTED` with the full stage results. History lists the last 30.
- Simulator customer search: customers that have sessions with **this merchant** only (FIX: today lists every customer on the platform), search by name/email/mobile; sessions list per customer with `isProcessable`.
- Sessions created while test mode is active are flagged `is_test = true` and excluded from merchant volume stats.
- Customer tokens are rejected on these endpoints (today's 403 rule) — automatically true with Filament auth.

### 5.17 Support tickets
- Created automatically for late payments (§5.11.6). Merchant panel: list, view messages, reply (ADMIN sender), mark RESOLVED. Customer-side ticket endpoints stay decommissioned.

---

## 6. HTTP API (Laravel `routes/api.php`)

Keep `/api/v1` paths that external parties call. Everything that only the old admin HTML called becomes a **Filament page/action** instead of a public JSON endpoint.

### 6.1 Public / integration endpoints (keep paths)

| Method & path | Auth | Section |
|---|---|---|
| `GET /health`, `GET /api/v1/health` | none | status, version, recovery stats, queue + Reverb + sidecar reachability (no secrets) |
| `POST /api/v1/payments` | secret or publishable key | §5.3 |
| `GET /api/v1/payments/{id}` | secret key | §5.3 |
| `GET /api/v1/payments/{id}/cod` | secret key | §5.3 |
| `GET /api/v1/orders/{orderId}/cod` | secret key | §5.3 |
| `POST /api/v1/customer/check-user` | none (rate-limited) | §5.4 |
| `POST /api/v1/customer/send-otp` | none (rate-limited) | §5.4 |
| `POST /api/v1/customer/verify-otp` | none (rate-limited) | §5.4 |
| `POST /api/v1/customer/register`, `/login` | none (rate-limited) | §5.4 |
| `GET /api/v1/customer/me`, `POST /logout`, `POST /switch-account` | customer token | §5.4 |
| `POST /api/v1/devices/register`, `/verify` | none (rate-limited) | §5.5 |
| `GET /api/v1/devices/{id}`, `/devices/customer/{customerId}` | customer token (own) / platform admin | §5.5 |
| `GET /api/v1/checkout/server-health` | none | §5.15 |
| `GET /api/v1/checkout/{id}/state` + all `POST /api/v1/checkout/{id}/*` actions | device access | §5.8 |
| `POST /api/v1/checkout/{id}/simulate-observation` | merchant developer/owner or platform admin + test mode | §5.8 |
| `POST /api/v1/pollers/android/pair`, `POST /api/v1/observers/sms/pair` | single-use pairing token | §13 |
| `POST /api/v1/pollers/android/events`, `POST /api/v1/observers/sms` | device id + HMAC (mandatory) | §13 |
| `POST /api/v1/pollers/android/heartbeat`, `/pollers/heartbeat`, `/observers/heartbeat` | device id (+ HMAC once the app is updated) | §13 |
| `GET /paykr-sms-gateway.apk`, `GET /api/v1/admin/sms-gateway/download-apk` | none | serves the APK file from storage (FIX: never write a fake stub APK) |
| `GET /paykr.js`, `/sdk/paykr.js`, `/sdk/paykr-checkout.js` | none, CORS `*` | §11 |
| `GET /checkout/{id}` | none | React checkout shell (§10) |
| `GET /checkout/{id}/qr.svg` | device access | vector QR of the session's `upiUri` (FIX: no `merchant@upi` / `100.00` fallback — 404 if not ACTIVE) |
| `GET /icons/{file}` | none | UPI app icons |

### 6.2 Internal endpoints (sidecars only; localhost + `X-Paykr-Internal-Token`)

| Old | New |
|---|---|
| `POST /api/v1/pollers/events` | `POST /internal/observations` (Google Messages sidecar → Laravel; body includes `merchantProfileId`/`merchantId`) |
| `GET /api/v1/pollers/active-window` | `GET /internal/active-window?merchantId=` (amounts the sidecar should look for; only that merchant) |
| — | `POST /internal/whatsapp/status` (sidecar pushes connection status / QR / pairing code) |

### 6.3 Old admin JSON endpoints → Filament

Every `/api/v1/admin/*` route in Appendix A1 maps to a Filament page or action:

| Old route(s) | New (Filament) |
|---|---|
| `auth/login`, `auth/logout`, `auth/me` | Filament login/logout (both panels) + 2FA |
| `queue`, `queue/clear`, `stream`, `payments`, `observers`, `counter-pending`, `counter-confirm`, `counter-decline`, `cod-orders` | Merchant ▸ Operations: Live monitor, Counter desk, Transactions, COD orders, Observers; actions "Clear waiting queue" (owner/manager), "Confirm", "Decline" |
| `upi-settings` (GET/POST), `upi-accounts` (GET/POST), `upi-accounts/{id}/activate`, `/toggle`, `DELETE upi-accounts/{id}` | Merchant ▸ Store settings ▸ Receiving accounts (resource with Activate/Deactivate/Delete; delete blocked while slot busy) |
| `all-settings`, `merchant-settings` (GET/POST) | Merchant ▸ Store settings ▸ Brand identity, Payment methods; Developer ▸ Webhooks & API keys |
| `gmail/credentials` (GET/POST), `gmail/oauth/start`, `gmail/disconnect`, `gmail/test` | Merchant ▸ Developer ▸ Gmail (OAuth connect button, test, disconnect). Credentials never returned to the browser after saving. |
| `whatsapp/status`, `qr`, `reconnect`, `pairing-code`, `disconnect`, `test-message` | **Platform admin** ▸ WhatsApp (single platform sender) |
| `sms-gateway/pair-qr`, `sms-gateway/devices`, `DELETE sms-gateway/devices/{id}` | Merchant ▸ Developer ▸ Android SMS (QR with single-use token, device list, unpair) |
| `dev/test-mode` (GET/POST), `dev/test-mode/inject`, `dev/test-mode/audit`, `dev/simulator/*` | Merchant ▸ Developer ▸ Payment simulator |
| `security/banned-users`, `banned-users/{id}`, `unban`, `manual-ban`, `security/events`, `security/history`, `customers` | Platform admin ▸ Security (bans, evidence, events, history, customers); Merchant ▸ Customers (blocks, own security events) |
| `tickets`, `tickets/{id}/resolve` | Merchant ▸ Support tickets |
| `tunnel/status`, `tunnel/start`, `tunnel/stop`, `dev/false-narrative` (GET/POST) | **Dropped** (§18) |

If a JSON admin API is still wanted (mobile app, automation), expose it under `/api/v1/merchant/*` with Sanctum tokens and the same role rules — do not re-create the old unauthenticated shape.

---

## 7. Real-time (Reverb)

| Old SSE topic / event | New channel / event |
|---|---|
| topic `<paymentId>`: `status_change`, `state_change`, `account_blocked` | **private** channel `payment.{paymentId}` (authorized by device id + device token, or customer token bound to it): events `PaymentStatusChanged {status, queuePosition, expiresAt, processingExpiresAt, method, timeRemainingSeconds, verificationLevel, verificationTag, sources, reason, message, blockedNotice}`, `PaymentAccountBlocked` |
| topic `admin_counter_events`: `status_change`, `counter_payment_requested` | private `merchant.{merchantId}.live`: `LivePaymentUpdated`, `CounterPaymentRequested` (Filament pages use Livewire `#[On('echo-private:…')]` or polling fallback) |
| topic `queue_<accountId>`: `queue_update` | private `merchant.{merchantId}.live`: `QueuePromoted {promotedId}` + to each queued session's `payment.{id}` channel |
| checkout listens for `payment_success`, `session_expired`, `queue_cleared`, `fraud_detected`, `counter_payment_confirmed`, `counter_payment_declined` | all expressed as `PaymentStatusChanged` with the right status; the React client maps them |

The checkout must also poll `GET /state` every 5 s as a fallback when the socket is down (today it reloads state on reconnect).

---

## 8. Filament — Platform Admin panel (`/admin`)

1. **Dashboard:** platform volume today/7d (excluding test sessions), success rate, active sessions, queue lengths, observer health per merchant, webhook failure count, sidecar status.
2. **Merchants** resource: create/edit/suspend, owner invite, API keys (view prefixes, revoke), impersonate (logged).
3. **Customers** resource: search, view sessions across merchants, ban/unban (with notes), evidence view (§5.12).
4. **Security:** banned users, security events (with raw evidence), ban/unban history.
5. **WhatsApp sender:** status, QR (data URL) or 8-digit pairing code by phone, reconnect, disconnect (wipes credentials), send test message.
6. **Payments (all merchants):** read-only table with filters; force-close stuck session (→ CANCELLED via transition service, audited).
7. **Webhook deliveries (all merchants):** failures, resend.
8. **Audit log** viewer (filter by merchant, actor, action, entity).
9. **System:** queue/Horizon link, clock worker heartbeat, Reverb status, sidecar health, failed jobs.
10. **Platform settings:** default app name/colours (white-label defaults), OTP rate limits, APK upload (versioned).

## 9. Filament — Merchant panel (`/merchant`, tenant = merchant)

Maps the 16 sub-views of `apps/merchant-admin/src/views/admin.html`:

| Old section ▸ sub-view | New page |
|---|---|
| Operations ▸ Live monitor | Dashboard: today's volume, successful payments, waiting queue, pending counter payments, single vs dual verified counts, current slot (ACTIVE 5-min / PROCESSING 30-s with countdown), queue list, "Force refresh", "Clear waiting queue" |
| Operations ▸ Counter desk | Live pending counter payments with Confirm / Decline (reason) |
| Operations ▸ Transactions | Payments table: order id, payment id, amount, method, status, verification tier, sources, created/completed; filters; detail page with timeline (audit), observations, webhook deliveries |
| Operations ▸ Observers | Android devices (connected if heartbeat < 3 min), Gmail status, Google Messages status, enrollment/pairing shortcut |
| Store settings ▸ Brand identity | Branding form + live phone mockup preview (port the mock from admin.html as a Blade/Livewire component), quick palettes (Sapphire Blue, Emerald Green, Violet Purple, Rose Crimson, Amber Gold, Obsidian Dark) |
| Store settings ▸ Receiving accounts | VPA resource (add, activate, deactivate, delete; one active) |
| Store settings ▸ Payment methods | Selling mode cards + method toggles + counter instruction texts |
| Developer ▸ Payment simulator | Test mode toggle with countdown; customer search; session picker; SMS stream and Gmail stream injectors (sender, content, received time); simulation history; full stage-by-stage result viewer |
| Developer ▸ System supervisor | **Dropped** (platform-level only) |
| Developer ▸ Cloudflare tunnel | **Dropped** |
| Developer ▸ Webhooks & secrets | Webhook URL, reveal/rotate secret, deliveries log, test webhook, API keys (publishable/secret, live/test, rotate), allowed origins |
| Developer ▸ Android SMS | Pairing QR (single-use token, 10 min, auto-refresh), APK download, paired devices with last heartbeat/observation, unpair, "Test bank SMS parser" (parse-only preview, never attributes — the current button is broken: it posts without a device id) |
| Developer ▸ Gmail & WhatsApp | Gmail OAuth connect/test/disconnect; WhatsApp shows read-only platform sender status |
| Security ▸ Banned users / events / history | Merchant-scoped customer blocks and security events for their sessions |
| Header | Store status, active VPA badge, light/dark theme toggle, live-sync indicator, log out |

## 10. Checkout (React SPA)

### 10.1 Shell
`GET /checkout/{id}` returns a Blade shell with: `<div id="root">`, initial state JSON (same allow-listed shape as `/state`, escaped), branding CSS variables, and the Vite bundle. Query params: `embedded=1`, `customAmount`, `customName` (display only).

### 10.2 Views (from `checkout.html`)
`viewUpiContent` (QR + timer + provider logos + instructions), `viewQueuedContent` (position + estimated wait), `viewProcessingContent` (30-s verification), `viewSuccessContent` (UPI or "Order Placed (Cash on Delivery)!" with COD API hint), `viewFailedContent` (failed/expired/cancelled with retry), `viewCodContent`, `viewCashContent`, `viewCardContent` (counter instructions), `viewBlockedContent` (fraud notice, case info copy), `viewDuplicateSessionContent` (end-and-restart). Plus: splash screen, sign-in sheet (steps `mobile` → `signup` → `otp`), saved accounts / account switcher (localStorage `paykr.customer.identity`, `paykr.customer.accounts`, `paykr.customer.activeId`, token `ypg_customer_token`), cancel-payment modal, help modal (support emails/mobiles, privacy/terms links), order details drawer, method selector (tabs on mobile, cards on desktop), downtime alert with "Pay via Cash on Delivery / Cash at Till instead".

### 10.3 Behaviour
- OTP input auto-submits on 6 digits; 30-s resend cooldown.
- Timer driven by server `expiresAt` + `serverNow` offset.
- **Payment Done** button appears once ≤ 4:00 remains (after 1 minute), on **both** mobile (bottom bar) and desktop (under the QR) — the desktop button was added on 2026-10-18.
- Processing view shows "Checking UPI transaction in real-time…" and a 30-s countdown.
- Success: show merchant name + "verified by `<appName>` banking observers", then postMessage to the parent; when standalone, redirect to `returnUrl` after 2 s.
- Blocked: render notice, "Exit" posts `paykr.payment_blocked_exit`.
- All user-visible strings white-labelled with `branding.appName`.

### 10.4 postMessage protocol (to `window.parent`, must stay compatible with `paykr.js`)
`paykr.modal_ready`, `paykr.payment_success {data: state}`, `paykr.payment_failed {data:{orderId, reason, paymentId, status, redirectUrl}}`, `paykr.payment_blocked`, `paykr.payment_blocked_exit`, `paykr.payment_cancelled`, `paykr.modal_close`, `paykr.close`, `paykr.cancel` (`{redirectUrl}`), and `{type: 'PAYKR_PAYMENT_HOOK', action, paymentId, orderId, details}`. Parent → iframe: `paykr.request_cancel`.
**FIX:** post to the merchant's origin (from `document.referrer` validated against `allowed_origins`), not `'*'`.

### 10.5 Customer-facing texts that must be preserved
Fraud notice title `FRAUDULENT ACTIVITY DETECTED`, subtitle `Payment Session Terminated & Account Suspended`, message from `index.js` FRAUD_BLOCKED handler; banned API message "Your account has been temporarily blocked because suspicious payment activity was detected. Our team will review the activity before access can be restored."; duplicate-session message "This payment session was created on another device. For security reasons, this session cannot be continued from this device."

### 10.6 Public branding allow-list and defaults
`appName` (Paykr), `primaryColor` (#2563eb), `headerTheme` (gradient), `logoUrl` (null), `sidebarNotice` ("Fast & Secure 256-Bit Encrypted Payments"), `subbannerText`, `trustBadgeText`, `hideTrustBadge`, `hideSubbanner`, `hidePoweredBy`, `hideTimeSavingBanner`, `checkoutNotice`, `footerGuarantee` ("Secured by Paykr 256-Bit Encryption"), `counterInstructions` ("Please visit the billing counter and present this screen to the cashier."), `cardCounterInstructions` ("Please swipe, insert, or tap your credit or debit card on the store POS terminal at the billing desk."), `termsUrl`, `privacyPolicyUrl`, `supportEmails`, `supportMobiles`, `sellingMode`, `enabledPaymentMethods`. Nothing else leaves the server. Escape every value (FIX: today they're inserted raw into the HTML template).

## 11. `paykr.js` merchant SDK (keep plain JS; port `apps/checkout-portal/src/sdk/paykr.js`)
- Script attributes: `data-paykr-key` (alias `data-key`, `data-merchant-id`), `data-api-url`, `data-portal-url` (alias `data-checkout-url`), `data-currency`, `data-name`, `data-theme-color`, `data-success-url`, `data-failed-url`, `data-suspicious-url`, `data-expired-url`, `data-cancelled-url`. Defaults to the script's own origin.
- Auto-binds `[data-paykr-pay]` buttons (also those added later): `data-order-id`, `data-amount`, `data-currency`, `data-name`, `data-description`, `data-customer-name|email|phone|mobile`, `data-metadata` (JSON), `data-checkout-url`, `data-payment-id`, and the five redirect URLs.
- `Paykr.pay(options)` / `PaykrCheckout.open(options)`: `order_id|orderId`, `amount`, `currency`, `customer{name,email,phone}`, `metadata`, `description`, `checkoutUrl`, `paymentId`, `handler(response)`, `onClose()`, redirect URLs. Creates the payment via `POST {apiUrl}/api/v1/payments` (`X-Merchant-Key`), then opens the modal (blur backdrop, centered on desktop, bottom sheet on mobile, close button asks the iframe to cancel).
- Window events: `paykr:payment:success|failed|suspicious|expired|cancelled` + `paykr:payment:result` with `{status, order_id, amount_received, payment_mode, utr, timestamp, metadata, raw}`.
- Redirects append `order_id`, `amount`, `utr` (success) / `reason` (failed).
- **FIX:** verify `event.origin === portal origin` before trusting any message (today any window can fake `paykr.payment_success`).
- On localhost pages, a remote checkout URL is rewritten to the local host (generic rule, see current file).
- Ship a minified build + SRI hash in the merchant panel's integration snippet.

## 12. Server SDKs
- **PHP** (`paykr/paykr-php`): `createPayment`, `getPayment`, `verifyWebhook(rawBody, signatureHeader)` (constant-time, `sha256=` prefix).
- **Node** (`@paykr/merchant-sdk` today, `packages/merchant-sdk/src/client.js`): keep publishing it with the same API (`createPayment`, `getPayment`, `verifyWebhook`), switching auth to the secret key.

## 13. Android observer app contract (keep the current APK working)
- Pairing QR JSON: `{version:'v1', type:'PAYKR_SMS_GATEWAY_PAIR', serverUrl, gatewayUrl, apiBaseUrl, sessionId, token, enrollmentToken, merchantId, createdAt, expiresAt}`. `enrollmentToken` is a single-use token (`pair_<48 hex>`, 10 min) stored hashed in `observer_pairing_tokens`; consuming it binds the device to that token's merchant. The static `ANDROID_ENROLLMENT_SECRET` is accepted only in local/testing.
- `POST /api/v1/pollers/android/pair {enrollmentToken, deviceName, deviceModel, merchantId, version, expiresAt}` → `{paired: true, deviceId, signingKey, merchantId}`. Errors: 401 invalid/used token, 400 `PAIRING QR EXPIRED`, 400 `INVALID Paykr PAIRING QR` (version ≠ v1). Audit `SMS_GATEWAY_DEVICE_PAIRED`.
- `POST /api/v1/observers/sms` (and `/pollers/android/events`): headers `X-Device-Id`, `X-Timestamp` (ms), `X-Signature = hex(HMAC-SHA256(signingKey, rawBody + ":" + timestamp))`. Mandatory; drift > 60 s → 401. Body: `body|sms|rawSms|message|text` or `rawNotification.text`, `sender` (or `rawNotification.package`), `observedAt`, optional `amountPaise`, `utr`, `senderVpa`, `receivingAccountId`, `paymentSessionId`, `isTest`. Goes through the pipeline (§5.10) with source SMS and device info `"<name> (<model>)"`. Non-bank SMS with no amount → 200 `PROCESSED_NON_BANK`. Updates `last_observation_at`.
- Heartbeat: `POST /api/v1/pollers/android/heartbeat` (`X-Device-Id`) → `{status: 'HEARTBEAT_ACK', timestamp}`. Plan an app update that signs heartbeats too.
- APK served from storage; platform admin uploads new versions.

## 14. Sidecars

### 14.1 WhatsApp sidecar (Node, BerryProtocol/Baileys) — `services/whatsapp-sidecar`
Port `apps/payment-api/src/services/whatsapp-berry.service.js` as a tiny HTTP service (Fastify or node:http) bound to 127.0.0.1, auth `X-Paykr-Internal-Token`.

| Endpoint | Behaviour (from the current service) |
|---|---|
| `GET /status` | `{status: DISCONNECTED\|QR_READY\|PAIRING_CODE_READY\|CONNECTING\|CONNECTED, phoneNumber, platform, sessionId, isConnected, qrCode (data URL), pairingCode, pairingPhone}` |
| `POST /connect` | `initialize()`; wait up to 8 s for QR; return status + QR |
| `POST /pairing-code {phoneNumber}` | 8-digit code (9 s timeout) |
| `POST /disconnect` | logout + wipe auth folder, meta file and store db |
| `POST /send {to, message}` | send text; auto-reconnect with saved creds; broken socket → reconnect; returns `{success, delivered, id, status}` or `{success:false, queued:true, status, message}` when not connected |
| `POST /send-otp {to, code, name, appName}` | template: "🔐 *<appName> Verification Code*\n\nHello *<name>*! Your one-time login OTP is: *<code>*\n\n⏱ _Valid for 5 minutes. Do not share this code with anyone._\n\n— *<appName> Secure Payments*" |

Pushes status changes to `POST /internal/whatsapp/status`. Auth folder under the sidecar's own data dir (never in the web root). Remove the hard-coded `D:/…` module paths from the current loader. Test-mode guard: in `APP_ENV=testing` or for dummy numbers (`910000000000…`, `0000000000…`, `919999999999…`) simulate delivery.

### 14.2 Google Messages sidecar (Node + Chrome CDP) — `services/messages-sidecar`
Port `apps/sms-observer` + `packages/observer-sdk/src/browser-launcher.js`, **one Chrome profile per merchant** (`profiles/<merchantId>`), debugging port per profile.
- `POST /profiles/{merchantId}/pair` → opens visible pairing window (`https://messages.google.com/web/authentication`), state monitor every 2 s, on pairing switch to background (`--window-position=-2400,-2400`).
- `GET /profiles/{merchantId}/status` → `{isPaired, status: STANDBY|AWAITING_PAIRING|CONNECTED, mode, messagesObserved, lastSeenAt, lastMessageAt}` (CONNECTED = paired + background + seen < 5 min).
- `POST /profiles/{merchantId}/sweep` → immediate extraction.
- Extraction loop every 3 s: read conversation items and message text; **FIX:** forward the conversation's real sender name (the current code labels everything `Google-Messages-Web`, which defeats the bank-sender filter) and the raw text; **do not** treat "text contains the active amount" as a payment (remove the "active window fallback"). Laravel's pipeline decides.
- Sends `POST /internal/observations {merchantId, source: 'GOOGLE_MESSAGES', sender, rawText, observedAt, messageHash}` (HMAC-signed with the internal token).
- Disabled unless `MESSAGES_SIDECAR_ENABLED=true` (today `ENABLE_LEGACY_CHROME_OBSERVERS`).

## 15. Gmail observer inside Laravel (replaces `apps/gmail-observer`)
- OAuth per merchant: "Connect Gmail" → Google consent (`gmail.readonly`, `userinfo.email`, `access_type=offline`, `prompt=consent`) → callback `/merchant/gmail/callback` (state = signed merchant id + nonce). Store tokens encrypted. Redirect URI = `GMAIL_REDIRECT_URI` (FIX: never the old hard-coded third-party domain).
- `PollGmail(merchant)` job: refresh access token (cache until expiry − 120 s); list `q=subject:(credit OR credited OR received OR upi OR deposit OR alert)`, `maxResults=10` (better: `users.history.list` from `last_history_id`); fetch new message ids not yet processed (dedupe table, keep 30 days); extract body (text/plain → text/html stripped → any part → snippet); build `{subject, body, from, date}`; run the pipeline with source GMAIL and sender = From header.
- Cadence: every 5 s per connected merchant while it has an ACTIVE/PROCESSING session, every 60 s otherwise; burst via `PollGmailNow` during PROCESSING (§5.7).
- Test connection: fetch `users/me/profile`, store `connected_email`, `last_tested_at`.
- Disconnect: revoke token at Google, clear tokens.
- The Gmail **browser automator** (Chrome scraping of mail.google.com) is dropped (§18).

## 16. Security requirements (consolidated)
1. Filament auth for both panels; platform admins require TOTP 2FA; login throttling (5/15 min per IP+email); session cookies Secure+HttpOnly+SameSite=Lax; CSRF on all web routes.
2. Merchant secret keys hashed; publishable keys origin-checked + rate-limited (60/min per key per IP for payment creation).
3. Encrypt at rest: webhook secrets, Gmail tokens, Android signing keys.
4. Customer tokens and device tokens stored hashed; OTP hashed; OTP attempt/send limits (§5.4).
5. No admin, simulation or observer-ingest endpoint reachable without auth (the 2026-10-18 Node fixes: admin login, internal token, single-use pairing, mandatory SMS signatures — keep all).
6. Pipeline rejection is final (§5.10 FIX). Observations scoped to their receiving account (§2.2).
7. Public responses use explicit field allow-lists (§5.8, §10.6). No secrets in branding.
8. Escape all merchant-controlled strings in HTML; strict CSP on the checkout (`frame-ancestors` = merchant allowed origins).
9. Webhook SSRF protection (§5.13).
10. No shell execution from user/merchant input (drop the tunnel command feature).
11. Audit every admin/merchant mutation with the real actor id and IP.
12. Production refuses template secrets (`APP_KEY`, admin password, internal token) — port the `PUBLIC_TEMPLATE_VALUES` rule.
13. Rate limits: check-user, send-otp, verify-otp, devices/register, payments create, trigger-hook.

## 17. Configuration (`.env`) mapping

| Old | New |
|---|---|
| `PAYMENT_API_PORT`, `CHECKOUT_PORTAL_PORT`, `MERCHANT_ADMIN_PORT`, `HELP_PORTAL_PORT`, `GMAIL_OBSERVER_PORT`, `SMS_OBSERVER_PORT`, `COIN_STORE_PORT`, `CONTROL_CENTER_PORT`, `CHECKOUT_PORT` | gone (one app). Sidecars: `WHATSAPP_SIDECAR_URL`, `MESSAGES_SIDECAR_URL` |
| `BASE_PAYMENT_API_URL`, `BASE_CHECKOUT_URL`, `BASE_ADMIN_URL`, `BASE_HELP_URL`, `BASE_DEMO_STORE_URL`, `PAYMENT_API_INTERNAL_URL` | `APP_URL` |
| `PAYKR_MODE`, `CLOUDFLARE_DOMAIN`, `DEPLOYMENT_PREFIX`, `CLOUDFLARE_TUNNEL_COMMAND` | gone (deployment concern) |
| `DATABASE_URL`, `USE_SQLITE_FALLBACK`, `SQLITE_DB_PATH`, `DEV_DB_PATH` | `DB_*` (MySQL) |
| `ENCRYPTION_MASTER_KEY` | `APP_KEY` (Laravel encrypter) |
| `JWT_SECRET`, `ADMIN_SESSION_SECRET` | `APP_KEY` + `PAYKR_INTERNAL_TOKEN` |
| `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | `PAYKR_ADMIN_EMAIL`, `PAYKR_ADMIN_PASSWORD` (seeder only) |
| `PAYMENT_ACTIVE_WINDOW_MS`, `PAYMENT_PROCESSING_WINDOW_MS`, `LATE_OBSERVATION_WINDOW_MS`, `PAYMENT_GRACE_PERIOD_MS` | `PAYMENT_ACTIVE_WINDOW_SECONDS`, `PAYMENT_PROCESSING_WINDOW_SECONDS`, `LATE_OBSERVATION_WINDOW_MINUTES`, `PAYMENT_GRACE_PERIOD_MS` |
| `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REDIRECT_URI`, `GMAIL_POLL_INTERVAL_MS` | same names (platform default OAuth app; merchants may use their own) |
| `SMS_OBSERVER_POLL_INTERVAL_MS`, `ENABLE_LEGACY_CHROME_OBSERVERS` | `MESSAGES_SIDECAR_ENABLED`, sidecar config |
| `ANDROID_ENROLLMENT_SECRET`, `ANDROID_HEARTBEAT_TIMEOUT_MS` | `ANDROID_DEV_ENROLLMENT_SECRET` (local only), `ANDROID_HEARTBEAT_TIMEOUT_SECONDS` |
| `WEBHOOK_MAX_RETRIES`, `WEBHOOK_INITIAL_BACKOFF_MS`, `WEBHOOK_RETRY_INTERVAL_MS` | `WEBHOOK_MAX_RETRIES`, backoff in config |
| `DEFAULT_APP_NAME`, `DEFAULT_PRIMARY_COLOR`, `DEFAULT_SUPPORT_EMAIL` | platform settings (DB) with env defaults |
| `COIN_STORE_WEBHOOK_URL`, `COIN_STORE_WEBHOOK_SECRET` | demo seeder only |
| `NODE_ENV` | `APP_ENV` |

## 18. Dropped / replaced (each needs no implementation)

| Item | Why / replacement |
|---|---|
| Control Center (`apps/control-center`, :8090, start/stop/restart/logs API, dashboard) | Supervisor/systemd + Horizon + Filament System page |
| Tunnel manager (`services/tunnel-manager.js`, `/admin/tunnel/*`, `cloudflareTunnelCommand`, `dev.js` tunnel spawn) | Runs arbitrary shell commands; tunnels are deployment config (`cloudflared` service) |
| "False narrative" dev switch (`/admin/dev/false-narrative`, `falseNarrativeUntil/Mode`) | Shows customers a fake UPI-server status to steer them to cash; misleading. Not ported. |
| Gmail browser automator (`apps/gmail-observer/src/browser-automator.js`) | Replaced by Gmail API job (§15) |
| Gmail observer service (:6003) routes `/oauth/url`, `/oauth/callback`, `/api/sweep`, `/process-email`, `/stop`, `/api/browser/*` | Laravel OAuth + jobs |
| SMS observer HTTP (:6002) `/process-sms`, `/api/sweep`, `/api/browser/*` | Messages sidecar API (§14.2) |
| Help portal (`apps/payment-help`, :3000) | Static help page inside the checkout (help modal) |
| Customer payments/tickets endpoints | Decommissioned today (404) |
| `autoActivate` flag on `POST /payments` | Test-only; use factories |
| Hard-coded pairing tokens, `X-Internal-Render` bypass, gateway-wide attribution fallbacks, Gmail "sync to all merchants", `queue/clear` cross-merchant, fake APK stub, `D:/…` module paths, `scripts/*` that read another developer's machine | Bugs / leftovers |
| Demo store (`apps/coin-wallet-store`) | Separate example project; keep its contract (§19.3) |
| `/api/v1/customer/*` decommissioned + `/api/admin/tickets` (merchant-admin local) | Filament tickets page |
| `admin-auth.js` HMAC tokens, `/admin/auth/*` | Filament auth |

## 19. Reference material

### 19.1 Current UI to mirror
- Checkout: `apps/checkout-portal/src/views/checkout.html` (6,877 lines; all views in §10.2).
- Merchant admin: `apps/merchant-admin/src/views/admin.html` (7,093 lines; sections in §9).
- Screenshots of every screen: `docs/demo/img/*.jpg`; workflow diagrams: `docs/flows/paykr-workflows.html`.

### 19.2 Known bugs in the current Node code that must NOT be ported
Listed inline as **FIX** above. Summary: pipeline-reject fall-through to attribution; gateway-wide attribution fallbacks; `bind-customer` accepts bare `customerId`; unauthenticated `cancel`; `X-Internal-Render` bypass; branding/metadata spread into public state (Gmail tokens would leak); `check-user` enumeration; `Math.random` OTP without attempt limits; Gmail config copied to all merchants; `queue/clear` across merchants; webhook not sent on daemon/stale expiries; counter-decline on closed sessions; `/expire` without time check; fake APK stub; QR fallback to `merchant@upi`; unescaped branding in HTML; SDK trusts any postMessage origin; Google Messages sender label and amount-only fallback; Gmail "healthy" with only a token; tunnel shell execution.

### 19.3 Demo store contract (for the separate example project)
`/` store, `/wallet`, `/order-success|failed|suspicious|expired|cancelled` (+ short aliases; params escaped), `GET /api/wallet-balance`, `POST /api/checkout` (server-side createPayment with `metadata.coins`), `POST /api/verify-payment` (re-check via getPayment, idempotent credit), `POST /api/webhook` (verify signature, credit coins on SUCCESS, idempotent by orderId).

## 20. Acceptance
1. Every test case in Appendix A8 has an equivalent Pest test (unit or feature) that passes.
2. New tests for every **FIX** in this document.
3. Tenancy architecture test (§2.2.1) and cross-merchant attribution test (merchant A's SMS can never complete merchant B's order).
4. End-to-end (Playwright): store → checkout → OTP → QR → simulated Android SMS → success page → webhook received → merchant panel shows the payment; plus COD, counter confirm/decline, queue of two customers, cancel during grace window stays CANCELLED, late payment creates a ticket, fraud injection bans the customer.
5. Load: 50 concurrent customers on one receiving account → exactly one ACTIVE at a time, FIFO order preserved, no double activation (MySQL row locks).

## Phase 11 scope decision — 2026-10-18

Owner confirmed that Node was never in production. Historical import, production-data
reconciliation, Node freeze/DNS cut-over and rollback to Node are not applicable. Preserve
current Laravel data/configuration/pairing. First-deployment preparation replaces cut-over.
Owner selected isolated local rehearsal instead of hosted staging; hosted acceptance remains
a future deployment gate. SDK 2.0.0 is packaged locally; public registry publication is a
release-owner action, not performed during this rehearsal.

The standalone demo replaces insecure legacy direct popup credits with authenticated
getPayment verification and signed matching-order webhooks. It does not reproduce the old
API/checkout reverse proxy or decorative marketing screenshots; canonical Laravel checkout
URLs and an optional single-wallet example fulfill the integration contract. Query-string
success pages never claim verified payment without a credited stored order.
