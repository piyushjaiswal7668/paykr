# Migration notes

## Phase 0

Implemented Laravel 12, Filament 5 / Livewire 4 panels, Sanctum, Reverb, Horizon, Predis,
Pest 3 (PHP 8.3-compatible), Pint and Larastan. Dependency versions are locked in composer.lock;
Composer resolves against PHP 8.3. Plain `final readonly` DTOs are the convention.

Shared foundations include the complete HTTP error-code catalog, translated messages, legacy/new
API exception shapes, request IDs, Actor, Money, MobileNumber, Vpa, and a Filament error helper.
Admin TOTP uses Filament's native provider with the specification's encrypted `two_factor_*` columns.
Admin and merchant guards are separate, preventing merchant authentication from bypassing admin MFA.
Horizon requires the admin guard and configured MFA even in local environments.

Users, merchants, and the membership pivot were brought forward from Phase 1 because tenant-aware
panels and seeded access cannot work without them. All other domain migrations and the SQLite import
remain Phase 1 work. The Phase 0 merchant has no receiving account or payment data yet.

Money accepts numeric input for compatibility but performs conversion using decimal strings and
integer operations. Values exceeding PHP_INT_MAX are rejected rather than overflowing. Future request
validation must preserve decimal precision before constructing Money; no float arithmetic is allowed.

The specification's wildcard architecture examples were retained with empty Data/Actions directories;
the merchant-owned-model rule becomes substantive as those models are introduced in Phase 1.
No old test is marked migrated merely because the new foundation tests pass. Health routes currently
provide the Phase 0 `{status: "UP"}` contract; complete health diagnostics remain pending.

## Local validation and remaining setup

Verified: 56 Pest tests / 489 assertions pass on PHP 8.3; coverage is 98.9% overall and 100%
for the domain. Pint passes, Larastan level 6 and domain level 8 pass, and composer.json validates.

PHP 8.3 is available at `/opt/homebrew/opt/php@8.3/bin/php`; default `php` is 8.4.
Native Homebrew MySQL 8.4.11 is installed and running at `127.0.0.1:3306`, with startup at login.
The `paykr` database has been migrated and seeded. All 56 tests / 489 assertions also pass against
an isolated `paykr_test` MySQL database, retaining 98.9% overall / 100% domain coverage.
The default quick-test configuration remains in-memory SQLite; MySQL verification used explicit
process environment variables and the isolated test account.

MySQL Workbench connection: Standard TCP/IP, hostname `127.0.0.1`, port `3306`, username `paykr`,
default schema `paykr`, password from `DB_PASSWORD` in the local `.env`. The application account has
privileges only on its own schema. Test credentials are in `storage/app/private/mysql-test.json`.
The newly installed root account has a generated password saved in
`storage/app/private/mysql-local-admin.cnf`. All credential files are mode 0600 and gitignored.

Homebrew Redis 8.10.2 is installed, running on loopback port 6379, and starts at login.
This differs from the Redis 7 target in the specification: all 56 tests also pass with live MySQL
and Redis 8 (isolated Redis databases 14/15 and a unique test prefix). CI retains Redis 7 to check
the specified baseline. Cache/session round trips, Horizon startup, and HTTP 200 responses for
`/health`, `/admin/login`, and `/merchant/login` have been verified. Temporary web and Horizon
verification processes were stopped; the Redis and MySQL services remain running.

XAMPP's binaries were left unchanged; its MySQL executable is x86_64
while this host is arm64, and reports `bad CPU type in executable`.

A temporary PCOV extension was compiled under `/private/tmp/pcov-1.0.12` for PHP 8.3.
To reproduce the coverage run in this session:

```sh
PHP_INI_SCAN_DIR=/private/tmp/paykr-php-ini /opt/homebrew/opt/php@8.3/bin/php /opt/homebrew/bin/composer test
```

Install/enable PCOV or Xdebug in your chosen PHP runtime for durable coverage support. No global PHP
configuration was changed. PHPStan's parallel worker socket is restricted in the sandbox; `--debug`
runs the same analysis sequentially. CI uses normal analysis and MySQL/Redis services.

Phase 0 local verification is complete on MySQL 8.4 / Redis 8, including migration, seeding,
coverage gates, and live login pages. Phase 1 is complete; see below. CI configuration is supplied but
has not been run on GitHub; Redis 7 compatibility remains to be verified there.


## Phase 1 — complete

Added the remaining domain tables, models, factories and typed enums. `BelongsToMerchant` applies
a fail-closed global scope: an absent tenant yields no rows. Instance writes require a matching
context, and tenant ownership cannot be changed on an existing model. Administrative imports
use an explicit `TenantContext::runUnscoped()` boundary that restores prior state even on failure.
The merchant panel sets context through persistent tenant middleware. Queue jobs must explicitly
establish their merchant context in later phases; there is no implicit CLI or admin bypass.
Raw SQL and explicit scope removal are privileged operations, not protected by Eloquent scopes.

Observations and ticket messages have an additional `merchant_id` so tenancy is directly enforceable.
Composite foreign keys also ensure that receiving accounts, observations, payments, Android devices
and tickets referenced by tenant-owned rows belong to the same merchant. Global audit/security rows
may retain a null merchant; tenant users cannot see those rows. A generated `active_marker` and unique
index enforce one active receiving account per merchant. MySQL enforces positive payment amounts;
SQLite tests skip that MySQL-specific CHECK test. MySQL connections use UTC explicitly.

The original device IDs are 40 characters, not UUIDs. Import assigns deterministic UUIDs internally,
preserves original IDs in `devices.legacy_id`, and remaps payment foreign keys. `Device::byPublicId()`
and `publicId()` support the retained identifiers; Phase 3 HTTP endpoints must use these helpers.
Customer password hashes remain byte-for-byte unchanged and PBKDF2 hashes receive a rehash flag.
Customer/device tokens and persisted OTPs are hashed; Android signing keys, webhook secrets and Gmail
credentials are encrypted. Historical webhook payload bytes and row timestamps are preserved.

Original branding is archived as encrypted, hidden `merchants.legacy_settings`; only allow-listed
branding fields enter `merchant_branding`. Gmail settings go into the encrypted connection model,
including an archived legacy API key (not used for authentication). Old deployment/tunnel settings
are retained only in the encrypted archive and are not executed. Missing origin allow-lists remain
unset: configure allowed origins before enabling publishable-key access. Historical placeholder audit
actor IDs are retained in `details.legacy_actor_id`, with nullable `actor_id`; no real identity is
invented. Legacy audit actor categories remain available for historical records.

`paykr:import-sqlite <path>` requires an empty destination domain database, reads SQLite in read-only
snapshot mode, imports within one transaction, resolves circular references after insertion, verifies
row counts, and refuses repeated imports rather than overwriting data. Existing admin users may be
present, but no merchant/domain rows may exist. Imports do not automatically grant users access to
merchants; membership assignment belongs to the administrative workflow.

Validation: 79 tests / 761 assertions pass on MySQL 8.4 and Redis 8; coverage 99.2% overall and 98.83%
domain. Pint, application Larastan level 6 and domain level 8 pass. The new migrations roll back and
reapply successfully on MySQL. The development `paykr` database has the Phase 1 schema and demo
receiving account, with its existing admin/merchant preserved. Four original database persistence
test names are marked complete in `docs/PARITY.md`.

The actual SQLite file imported successfully into the isolated `paykr_import_check` database:

| Legacy table | Source / imported rows |
|---|---:|
| merchants | 1 / 1 |
| payment_receiving_accounts | 2 / 2 |
| devices | 20 / 20 |
| payment_sessions | 20 / 20 |
| payment_observations | 7 / 7 |
| webhook_deliveries | 18 / 18 |
| audit_logs | 128 / 128 |
| customers, customer_sessions, android_devices, support_tickets, ticket_messages, security_events | 0 / 0 each |

The source SQLite SHA-256 was unchanged after import. Test credentials in
`storage/app/private/mysql-test.json` also have access to `paykr_import_check` for inspection;
no legacy data was merged into the development database. Phase 2 is in progress; see below.


## Phase 2 — primitives complete; payment workflows pending

Implemented canonical UPI/Android intent builders, the 30-entry provider registry with copied SVG
assets in `public/icons/upi`, SVG QR generation at 220/260 pixels using the already installed
chillerlan library, email normalization, and native password hashing plus legacy PBKDF2 verification.
Login-time rehashing remains part of the customer authentication workflow; this change only supplies
verification and rehash detection. The Node salt is hexadecimal text, not decoded binary.

Bank SMS/email parsing preserves the original ordered regular expressions. Observation normalization
uses readonly DTOs, explicit integer amounts, source enums, legacy timestamp fallback, and the same
SHA-256 reference/time-bucket fingerprint. Parsing is extraction only: it does not authorize a payment
or update payment state. The six-stage verification pipeline and attribution engine are still pending.

Device fingerprint serialization and P-256 verification accept PEM, base64 SPKI, JWK objects/JSON,
and both browser P1363 and DER signatures. `tests/Fixtures/device-security-parity.json` contains only
public keys and test signatures generated with Node; no private key is stored. Bank-message fixtures
were also generated by running the original Node parsers, including all six original amount examples.
Regenerate these fixtures from the project root using:

```sh
node scripts/generate-legacy-primitives-fixtures.mjs /path/to/YouPI-PG
```

Intentional boundary changes: Android package names reject intent-fragment injection; explicit paise
must be positive; PHP integer overflow is rejected rather than accepting Node BigInts beyond the
storage limit. Observation normalization shares the corrected bank amount extractor (currency word
boundary) instead of the older normalizer's duplicate regex. Raw email text is retained in the DTO;
consumers needing a legacy snippet must truncate it to 300 characters. UPI currency/extra parameters
are absent from the typed builder API and are never emitted. Password verification rejects malformed
legacy hashes and iteration counts above one million (the source always writes 100,000). Provider
metadata is copied for parity, not independently verified as a current app-store catalog.

Validation: 94 tests / 1,167 assertions pass against MySQL 8.4 and Redis 8. Coverage is 99.3% overall
and 99.12% domain; Pint and both Larastan configurations pass. Ten additional original test names are
checked in `docs/PARITY.md`. No application schema or development/import data changed in this step.

Still required before Phase 2 is complete: transactional state transitions and after-commit events,
account slot locking and the 50-customer MySQL concurrency test, verification/attribution and grace
jobs, fraud/ban services, device session-access enforcement, webhook delivery/retries, UPI health,
and the remaining parity and regression tests. Do not advance to Phase 3 yet.


## Phase 2 — transactional transitions and slot queue complete

`PaymentTransitionService` serializes state changes by locking the receiving account before the
session, rereads state under the lock, records actor-aware audit entries in the same transaction,
and emits immutable events only after the enclosing transaction commits. Terminal states reject
all further transitions; rollback discards state, slot changes, audit entries and pending events.
The named terminal events are `PaymentSucceeded`, `PaymentFailed`, `PaymentExpired` and
`PaymentCancelled`. Repeated/racing terminal calls return `PAYMENT_CLOSED` rather than emit twice.
These are in-process after-commit events, not a durable delivery guarantee across a process crash;
webhook persistence and idempotent delivery remain pending.

`SlotQueueService` locks one receiving account row for enqueue/promotion and uses the same transition
service for activation and stale-owner expiry. A waiting customer takes precedence over a newcomer.
Retries do not demote the current owner or extend its deadline. PROCESSING retains ownership.
Terminal-event listeners restore the event's explicit tenant context, promote the next customer,
and reindex remaining positions. Queued cancellation cannot release another customer's active slot.
Stale account pointers are repaired from locked live sessions; multiple live owners are rejected as
inconsistent instead of silently choosing one. Non-UPI payments never enter the slot queue.

State-graph clarifications required by the specification's other workflows: ACTIVE may become
SUCCESS for verified observations as well as COD/counter confirmation (§5.11); ACTIVE counter
payments may become FAILED for decline (§5.14); PROCESSING may be cancelled by the customer; every
live state may be expired for a global ban (§5.12). Terminal finality is unchanged. These services
are internal trusted domain operations: future HTTP/Filament callers must authorize the operation,
and only the verification pipeline may accept observer evidence for success.

`SessionActivated`, `PaymentStateChanged` and `QueueUpdated` expose domain changes for later realtime
integration. No WebSocket publishing or timer workers are wired in this step. Stale sessions are
cleaned when the queue is accessed; autonomous clock/recovery work belongs to Phase 3.

The new committed-transaction tests rebuild the disposable database per case so after-commit behavior
is exercised without a surrounding test transaction. The MySQL concurrency test starts 50 separate
PHP processes, opens each database connection, waits at a common barrier, then releases all requests.
It asserts one active owner and contiguous unique positions 1..49. A second barrier races ten
cancellations of that owner and asserts one terminal event/audit and promotion of the next customer.
Credentials travel through the child environment, never command-line arguments or fixture files.

No development database migration, legacy data change, HTTP route or payment-provider call is part
of this step. Remaining Phase 2 work: verification/attribution and grace jobs, fraud/ban services,
device session-access enforcement, durable webhook delivery/retries, UPI health, and remaining parity
and FIX tests. The earlier primitives section's pending transition/queue items are now complete.

Validation for this step: 106 tests / 1,408 assertions pass on MySQL 8.4 with Redis 8, including
both process-barrier races. Coverage: 99.2% overall / 99.01% domain. The default SQLite run passes
104 tests with two explicit MySQL-only skips. Pint and Larastan application level 6 / domain level 8
pass. Three more original test cases are checked in the parity ledger. CI has not been run remotely.


## Phase 2 — transactional merchant webhooks complete

This step completes terminal webhook delivery before verification/attribution so those upcoming
workflows can reuse the same durable outcome path. `PaymentTransitionService` now calls
`WebhookService::recordTerminal` inside its transaction, before after-commit events. A merchant with
no webhook URL is skipped, matching Node. A configured merchant gets one stored delivery per terminal
payment outcome; locking the payment serializes creation without imposing a new uniqueness constraint
on imported historical deliveries. The terminal status and delivery roll back together. Retrying a
closed transition still raises `PAYMENT_CLOSED` and creates no additional record.

The JSON body uses the legacy key order, exact integer paise, two-decimal rupees, UTC millisecond
completion time, verification UTR when present, and empty metadata as `{}`. It is serialized once.
The endpoint, body and delivery UUID remain stable on retries even if payment metadata or merchant
URL changes. Signing uses the merchant's current decrypted secret, as Node does, with the exact
stored bytes and `sha256=` prefix. A worker restores the merchant context from its IDs before reading
any tenant-owned row. No secret or payload travels in queue arguments.

`DeliverWebhook` uses Redis and the existing default Horizon queue. Requests have a 10-second total
and 5-second connect timeout; 2xx succeeds, response bodies are stored up to 1,000 characters, and
network/configuration failures store safe messages. A row lock serializes concurrent duplicate jobs.
HTTP success followed by a process/database failure can still lead to a repeated request: delivery is
at-least-once, and merchants must deduplicate the stable `X-Gateway-Delivery-Id`.

Retry state belongs to the delivery row, not Laravel job attempt counters. Failed attempts use
`[5,30,120,600,3600]` seconds with five total attempts by default (only the first four delays are
needed at that limit). `next_retry_at` is cleared on success/exhaustion. Redis queue availability is
rounded up to a whole second so it never precedes the database's millisecond deadline.

In production and other non-local/non-testing environments, endpoints require HTTPS and resolved
public addresses. Symfony's existing private-address range checks also reject mapped/tunnel addresses;
multicast is rejected explicitly. All DNS answers are checked, one validated address is pinned with
cURL for the request, and redirects/proxies are disabled. Local/testing permits HTTP/loopback to
support a local receiver. Credentials/fragments and non-HTTP URL schemes are rejected everywhere.

If enqueueing fails after commit, the due delivery survives and a safe warning is logged. The system
command `php artisan paykr:webhooks:retry-pending` re-enqueues up to 100 due rows across merchants;
each job then scopes itself back to its own merchant. Repeated recovery runs are safe under duplicate
jobs. Periodic recovery scheduling belongs to Phase 3; ordinary retries already schedule themselves.
Merchant-panel resend/test controls remain for the UI phase.

Tests use fake HTTP responses only. The MySQL concurrency test now additionally races ten delivery
workers against the one persisted cancellation webhook and verifies one request and one successful
attempt. No application migration or development/import data changes were needed. Verification,
attribution/grace jobs, fraud/bans, device-access enforcement and UPI health remain pending in Phase 2.

Validation: 115 tests / 1,532 assertions pass against MySQL 8.4 and Redis 8, including 50-customer
queue contention, ten racing cancellations, and ten duplicate delivery jobs. Overall coverage is
98.6%; domain coverage is 98.07%. Pint and Larastan application level 6 / domain level 8 pass.
SQLite passes 113 tests with two explicit MySQL-only skips. CI has not been run remotely.


## Phase 2 — verification, attribution and automatic fraud handling complete

The six-stage `VerificationPipeline` now validates source/sender, parses credit messages and
12-digit references, checks the session amount/time window and reference reuse, then checks
customer security before attribution. Rejected stages never fall through into attribution.
Its simulator envelope retains stage1–stage6 and returns the refreshed session status.
Production Gmail sender rules exclude development-only domains. Test flags alone cannot suppress
bans: suppression requires an unexpired persisted merchant test mode and a test payment.

`AttributionEngine` locks the receiving account before the payment, deduplicates each channel,
keeps attribution within that account/merchant, and persists the first observation and source set.
L1 waits for the configured grace period; a second source can complete L2. Recent successful
payments can gain verification evidence without a second success transition or webhook.
Cancelled/expired/failed orders remain closed. Late evidence is audited and emits a domain event;
customer-bound orders get one support ticket. Customerless orders cannot get a ticket because the
existing ticket schema requires a customer. The raw pipeline rejects observations timestamped
after session expiry; late attribution also covers historical evidence and cancellation during grace.

Migration `2026_10_18_000003` adds the indexed, nullable millisecond grace deadline and has been
applied to the local `paykr` database. Redis finalization jobs restore their merchant context and
recheck the deadline and status under locks. Queue dispatch failure leaves a recoverable deadline:
`php artisan paykr:verification:recover` queues up to 100 due payments. Periodic scheduling remains
Phase 3. Closed payments cannot be revived by recovery or a delayed grace job.

Customer-attributable forged credit messages preserve raw text and parser/verification evidence,
audit the ban, and expire all live sessions for that customer across merchants through the normal
terminal transition/webhook path. Test-mode suppression records evidence without banning or paying.
Typed domain events are present; realtime client delivery belongs to a later phase.

Regression tests cover rejected inputs, tenant/account isolation, timestamp boundaries, reference
reuse, L1/L2 grace, cancellation, late tickets, persisted test-mode suppression, global automatic
bans, rollback and queue failure recovery. Real MySQL workers race SMS/Gmail submissions and assert
one success, two channel observations, eight duplicate rejections and one webhook. HTTP is faked;
no real merchant deliveries were made. Manual ban/unban and evidence UI, device access enforcement,
UPI health and remaining legacy parity work are still pending; Phase 2 is not yet complete.

Final validation: MySQL 8.4 / Redis 8 passes 138 tests with 1,693 assertions, including the
multi-process queue, cancellation, delivery and observer races. SQLite passes 135 tests with
three explicit MySQL-only skips. Coverage is 98.5% overall and 98.20% domain. Pint and Larastan
application level 6 / domain level 8 pass. CI has not been run remotely.


## Phase 2 — core domain complete

Completed manual ban/unban and evidence queries (`BanService`, `BanEvidenceService`), live checkout
device access (`DeviceAccessService`) and merchant-specific UPI health (`UpiHealthService`).

Manual bans require a persisted platform-admin user; merchant actors and forged admin identities
are rejected before unscoped access. Ban/unban writes are transactional and idempotent, record the
actual actor plus notes, and preserve security history. The fraud pipeline reuses the same live-session
expiration path. Global bans expire each live payment through transitions, emitting its webhook;
unbanning never restores a closed payment. Transitions additionally reject new activation, queueing,
processing or success for banned customers or customers blocked by that merchant.

Evidence queries require the same platform authorization, restore the prior tenant context, retain
raw text verbatim, and calculate successful spend with integer paise. Evidence keeps the legacy shape
but selects the current ban type so a manual ban is not described as an older automatic fraud ban.
The searchable banned list deterministically selects the latest security event. Merchant event
browsing and merchant-block management UI remain later-phase integration work.

Device access locks the payment before binding. Live bound payments require both the matching public
device ID and token; only the token hash is stored. Missing/wrong tokens yield the specified duplicate
session response. Imported legacy public IDs remain usable. New placeholder devices receive a random
server-generated token once in the binding result; callers must retain it. Existing devices require
proof of the token before binding to an unbound payment. Revoked devices are rejected. Terminal
status remains readable, and an unbound payment can be read without a device as specified. No header
or internal-render bypass is introduced. Registration/HTTP authorization integration remains Phase 4.

UPI health derives GREEN/YELLOW/RED from this merchant's active Android heartbeat (under five minutes)
and Gmail refresh token plus a successful poll under five minutes old. Future timestamps do not count.
A typed merchant-specific Google Messages snapshot can supply SMS health only while fresh, paired,
CONNECTED and BACKGROUND, with polling or recent activity. Missing/stale sidecar data degrades health;
the authenticated sidecar adapter is Phase 6. Cash recommendations follow the merchant's selling mode
and enabled cash/COD settings. No external health requests or secret-bearing responses are produced.

`PaymentTimingService` supplies idempotent Payment Done, deadline-checked expiry/failure and scoped
recovery/promotion, using account-before-payment locks and existing transitions. This completes the
Phase 2 timing/chaos domain tests. Continuous clock operation, delayed expiry jobs, startup job
rescheduling and recovery statistics remain Phase 3, as specified in its build prompt.

Remaining common/core/db/chaos parity cases are now mapped in `docs/PARITY.md`. The original reset test
operates on production SQLite files; the replacement exercises cleanup only inside a disposable test
transaction and preserves merchants, accounts, payments and observations. No destructive reset feature
was added. Laravel's authenticated encrypted casts (AES-256-CBC plus MAC) replace Node's AES-GCM wire
format, with tamper tests; the importer already re-encrypts source values. Existing stored secrets and
cipher configuration were not changed. Admin evidence screens and public HTTP flows are later phases.
No database migration was necessary for this step; the development and imported datasets are unchanged.

Validation: 150 tests / 1,848 assertions pass on MySQL 8.4 and Redis 8. Coverage is 98.6%
overall and 98.42% domain. This includes 50 competing customers, ten simultaneous Payment Done
requests sharing one deadline, ten terminal requests, duplicate delivery jobs, and SMS/Gmail races.
Pint and Larastan application level 6 / domain level 8 pass. SQLite also passed the full suite before
the final fixture-cleanup test, and the database test file including that new case passed afterward.
All webhook HTTP is faked. No real merchant requests were sent; remote CI has not been run.


## Phase 3 — clock worker, jobs and recovery

`paykr:clock` acquires a renewable five-second Redis cache lease, recovers on startup, and runs on a
one-second monotonic cadence. It renews while scanning records and exits if ownership is lost. A
second process refuses to run. SIGTERM/SIGINT releases the lease; SIGKILL leaves it to expire before
supervisor restart. Database locks and terminal-state checks remain authoritative when work repeats.
The heartbeat becomes stale after three seconds and graceful shutdown marks it stopped.

Activation/processing events schedule `ExpireActivePayment`/`FailProcessingPayment` after commit,
with Redis availability rounded up to a whole second. Jobs re-read status and current deadline, so
an old UPI job cannot expire a new COD timer early. Startup recovery terminates overdue states,
promotes queues, and re-dispatches live expiry and future grace jobs. Counts include expired, failed,
rescheduled jobs and promoted sessions. The clock also finalizes due grace deadlines directly;
missed expiry/grace jobs use stored deadline order. Closed orders stay closed and terminal webhooks
still use the same transaction. Queue-dispatch failures retain the database deadlines.

`PaymentMethodService` supplies the domain behavior needed by the COD timer parity tests: UPI→COD
releases and promotes the account while creating an independent five-minute deadline; counter
payments have no expiry; returning to UPI joins the account queue. Enabled settings, tenant identity,
closed states and existing verification are checked. Switching after verification has begun is
rejected to avoid abandoning detected bank evidence. HTTP method-selection remains Phase 4.

Observation retries now reuse the original row through `AttributionEngine::reattribute`. Migration
`2026_10_18_000004` adds a nullable `verified_at` written only after pipeline stage 6. Imported or
otherwise unapproved observations are excluded from automatic retry; no raw-message filter can be
bypassed by restarting the worker. The same migration adds per-merchant Gmail message receipts.
It has been applied to the local development database without changing existing payment outcomes.

`PollGmail` and `PollGmailNow` refresh per-merchant tokens, list up to ten recent credit-subject mails,
fetch full bodies (plain text, HTML, other text, snippet), preserve the From sender, and call the real
pipeline. Message receipt and verification commit together; repeating a poll does not reprocess an
ID. Poll locks prevent overlap, receipts expire after thirty days, and successful polls update health.
Active sessions poll every five seconds; processing bursts are dispatched every two clock ticks
(the first whole-second tick after the 1.5-second minimum), idle connections every sixty seconds.
Burst dispatch also has a poll-time throttle. OAuth authorization UI remains Phase 6.

The clock only queues observer work. Optional Google Messages sweep jobs call the authenticated
merchant-specific sidecar path with an 800 ms timeout; they do not block deadline checks. The sidecar
is disabled by default and its implementation remains Phase 6. Failed observer dispatch is logged
without aborting payment timing. All new observer HTTP tests use fakes and prevent stray requests.

Horizon queues are ordered payments, webhooks, observers, default, with an 80-second timeout below
Redis's 90-second retry interval. Finalize jobs use payments; deliveries use webhooks. Scheduler
recovery runs every minute and Gmail receipt pruning daily. `/health` and `/api/v1/health` retain
`status: UP` for liveness and add safe clock readiness/recovery fields; `/admin/system` displays them
under the existing platform-admin login/2FA boundary. Blade compilation and route registration pass.

Supervisor/systemd examples are in `deploy/`, including FPM, Horizon, clock, Reverb, scheduler and
disabled-until-Phase-6 sidecars. They were not installed. The one-second SLA is measured on the local
acceptance workload; a tick still scans live payments/accounts, so larger installations need worker
capacity and tick-duration monitoring. Long database lock waits can also delay a tick; a lost lease
stops that worker and supervisor restart performs recovery. No test clock processes are left running.

Final Phase 3 validation: 163 tests / 1,949 assertions pass on MySQL 8.4 and Redis 8,
including the real singleton/SIGKILL/restart test and measured ≤1-second expiry/failure latency.
Overall coverage is 94.8%; domain coverage is 96.40% (gates: 80% / 90%). Pint and Larastan
application level 6 / domain level 8 pass. SQLite passed the full pre-final suite with four explicit
MySQL-only skips; the final future-grace recovery case also passed in its focused SQLite test file.
Migration application, scheduler registration, admin System route and Blade compilation pass.
No external Gmail/merchant HTTP or remote CI run was performed.

## Phase 4 — public API

Implemented the payment integration, customer identity, device and checkout action routes from
§5.3–5.5 and §5.8, plus authenticated SVG QR generation. Merchant API authentication accepts
hashed publishable/secret live/test keys, validates publishable-key origins, rejects revoked keys
and suspended merchants, and restores tenant context after every request. Payment reads require
secret keys. `PAYKR_ALLOW_LEGACY_MERCHANT_HEADER=false` is the default; enabling it permits
migration creation, never secret-key reads. `ApiKeyService` issues raw keys once to persisted
platform admins or merchant owners/developers; only hashes are saved.

Creation serializes order/idempotency decisions per merchant, locks accounts in consistent order,
resolves customer hints, checks bans/blocks, snapshots receiving accounts and leaves UPI payments
INITIATED until checkout authentication. Idempotency uses the original request hash so later method
changes do not invalidate a retry. The hash and receiving-account snapshot are removed from public
metadata. Unknown integration device IDs receive a placeholder and a one-time `deviceToken` in
the creation response; clients must retain it for subsequent checkout calls.

Customer routes support masked lookup, password registration/login, WhatsApp OTP challenges,
30-day hashed sessions, bearer tokens and HttpOnly SameSite=Lax cookies (Secure outside local/test).
Failed OTP attempts commit even when verification fails. Mobile/IP send budgets, resend cooldown,
per-challenge attempts and route limits apply. Signup uses the stored pending identity, never
replacement identity fields supplied during verification. No real WhatsApp message was sent by
the tests; the HTTP client is faked. The sidecar package and live sender setup remain Phase 6.

Device registration normalizes P-256 public keys and computes fingerprints from signals. Verify
accepts the existing DER/P1363 formats. Device inventories require the owning customer or a
persisted platform admin. Knowing a public key does not recover or rotate its existing token:
repeat registration returns `deviceToken: null` unless a valid existing token is supplied. This is
an intentional security change needed for hashed tokens. A client that loses its private token
must register a new key rather than recover a token using public information.

Checkout middleware validates device access without binding during input validation; the domain
service rechecks access and binds when executing. There is no internal-render header bypass.
Customer binding is authenticated and immutable per payment. Retry/restart additionally require
the customer session; a new device must prove its token. Restart cannot reopen a terminal payment.
Method selection, cancellation, payment-done, COD and expiry use the existing transition/queue
services. An early expiry hint cannot shorten the persisted deadline. SVG QR is returned only for
ACTIVE UPI. Stream/events return 410 with the Reverb channel hint; channel transport remains in
the later realtime phase. Simulation requires persisted staff authorization, a test payment and
an unexpired merchant test-mode record, and invokes the real verification pipeline.

Public branding is explicitly mapped; terminal state is a public receipt without customer details.
Nested credential/test-mode metadata is filtered. Legacy domain errors retain their endpoint
message/error fields while catalog codes, failure status and request IDs stay controlled.
Decommissioned customer payment/ticket endpoints remain absent. Android observer, sidecar,
checkout shell/SDK and Filament operations UI routes belong to their subsequent build phases.

HTTP acceptance coverage: `CustomerApiTest`, `DeviceApiTest`, `PaymentApiTest`, `CheckoutApiTest`.
The MySQL worker suite also submits ten simultaneous identical payment creations and checks for
one row and one created response. Existing domain, transition, timer, signature and tenant tests
remain in the acceptance suite. Exact final gate results are recorded below after validation.

Final Phase 4 MySQL 8.4 / Redis 8 acceptance: **184 tests, 2,213 assertions pass**.
Overall coverage is **94.0%**, domain coverage **94.79%** (required 80% / 90%).
Pint, application Larastan level 6, domain Larastan level 8 and route registration pass.
The acceptance suite includes the real clock kill/restart case, queue/observer concurrency and
new simultaneous API creation case. No development database import, schema reset, external
WhatsApp/Gmail/webhook send, deployment or Git commit was performed for Phase 4.
SQLite final regression: **179 passed, 5 MySQL-only tests skipped, 2,044 assertions**.

## Phase 5 — observer intake and Gmail OAuth

Android enrollment uses a hashed, single-use `pair_` token with a ten-minute server-side expiry.
The token determines the merchant; a submitted merchant ID cannot change its tenant. QR issuance
is exposed to authenticated platform admins or merchant owners/developers at
`POST /api/v1/merchant/{merchantId}/observers/pairing-token`. It returns the §13 QR JSON under
`data`, including all current APK field aliases. Pairing aliases, event aliases and all heartbeat
aliases are registered. Device signing keys are encrypted and returned only by pairing.

Observation HMAC verification uses the exact received JSON bytes plus `:` and the millisecond
`X-Timestamp`, a mandatory SHA-256 signature and a 60-second drift limit. Committed request hashes
are stored in MySQL, so clearing Redis or restarting Laravel does not permit a replay. Receipts
commit with intake, and expired receipts are pruned hourly. Concurrent pairing and signed-message
replays are covered using ten independent MySQL workers. The current APK's unsigned heartbeat
remains supported; it updates liveness only and cannot authorize observations. Signed heartbeats
also work. Upgrading the APK to require heartbeat signatures remains a client compatibility change.

The driver fixture preserves the old driver's exact request serialization and crypto contract;
only its package import is replaced with the identical Node crypto implementation. The Java SMS
shape is also tested. The real 288 KiB APK is served from protected storage by both download paths,
with a pinned SHA-256 assertion. The private-storage ignore rules expose only this release artifact
to version control, not credentials or other private files. No APK stub is generated.

`/internal/observations` and `/internal/active-window` require a loopback peer and the internal
header token. Observation POSTs additionally require the same timestamp/HMAC protocol. The
merchant is explicitly scoped; supplied foreign account/session IDs cannot cross that boundary.
Google Messages always supplies its real sender and raw text to the verification pipeline.
Structured amount/reference fields never manufacture evidence missing from the raw alert. The old
Android test's non-12-digit `UTR-ANDR-9876` and amount-only notification no longer finalize a payment;
this follows the mandatory pipeline contract rather than preserving the old bypass.

Gmail connect/callback/test/disconnect routes are under `/merchant/gmail/`, authenticated using the
staff session or Sanctum and restricted to owners/developers or platform admins. OAuth state is
authenticated encryption of merchant/user/nonce, backed by a single-use ten-minute DB record and
bound to the current user. Consent requests use configured redirect URLs, readonly Gmail scope and
offline access. Callback responses are static escaped success/denial/error pages; access tokens,
refresh tokens, secrets, authorization codes and provider error bodies are not rendered.

Tokens remain encrypted. Profile tests reuse the poller's refresh logic; OAuth mutations share its
per-merchant lock so disconnect cannot race a poll and resurrect credentials. Disconnect revokes
at Google before clearing credentials; a failed revoke returns an error and preserves credentials
for retry. Reconnecting a different mailbox clears that merchant's prior message receipts. Existing
five-second active, sixty-second idle and PROCESSING burst cadence, receipt deduplication and
verification/fraud behavior are unchanged. Gmail, Android and sidecar tests each finalize a payment
through the real pipeline, with external HTTP faked and stray requests prohibited.

Migration `2026_10_18_000005_create_observer_security_receipts` adds only request receipts and OAuth
state records. The older rollback test now determines its rollback depth from applied migrations
instead of assuming no future migrations exist. Node 22 is explicit in CI for the driver replay.
No live Google authorization, real phone SMS or external sidecar delivery has been claimed by these
automated tests. Panel buttons/resources remain Phase 8; Node sidecar packages are Phase 6.

Phase 5 validation: the complete MySQL 8.4 / Redis 8 run passed **199 tests / 2,371 assertions**,
including both new concurrency cases; coverage was **94.3% overall / 94.99% domain**. After the final
public-artifact error-status regression was added, all **15 observer/Gmail tests / 107 assertions**
passed again on MySQL. Final full SQLite regression passed **194 tests / 2,178 assertions**, with
six MySQL-only skips and **94.4% overall / 95.14% domain coverage**. Pint, Larastan application level
6 and domain level 8, Blade compilation and route registration pass. The additive migration was
applied successfully to the local development database; existing business data was not reset.
No live Google/phone connection, deployment, Git commit or external message delivery was performed.

## Phase 6 — Node sidecars (2026-10-18)

Added `services/whatsapp-sidecar` and `services/messages-sidecar`, Node 22–26 ES modules with
no build step and native `node:test` coverage. Both bind 127.0.0.1, authenticate every request
with the shared internal token, enforce JSON/body limits, and store profiles/auth outside the
webroot. The WhatsApp package pins BerryProtocol 0.1.10, qrcode and a silent Pino logger;
Windows-specific module paths and OTP/pairing logs are removed. It exposes connection, QR,
phone-number pairing, logout, text and branded OTP endpoints, reconnects saved credentials,
implements explicit test/dummy-number simulation and pushes status to Laravel. The callback
stores a singleton sender record without QR/pairing secrets.

Google Messages uses isolated merchant UUID profiles and ephemeral debugging ports. Its
pairing monitor runs every two seconds, extraction every three seconds, and paired windows
move offscreen. Only full bubbles with a real sender and parseable original timestamp are
forwarded. Recent conversations are rotated, failed forwards retried, and sender/text/time
are hashed for deduplication. The old amount-only fallback, synthetic sender and full-body
scan are removed. Exact JSON bytes are HMAC-signed using the existing Phase 5 intake contract.
Google DOM selectors are conservative: unsupported sender/time markup is skipped and still
requires acceptance with a paired Android account.

Laravel has feature-flagged clients, 1-second connection/2-second total deadlines and a
15-second circuit cooldown. Cached circuit timestamps make Redis and in-memory behavior
consistent with the application's clock. WhatsApp failure leaves dispatch undelivered;
Messages failures degrade UPI health to RED/YELLOW according to the remaining Android/Gmail
sources. New requests use DTOs and a thin status callback controller. No public panel controls
are added ahead of Phase 8. No migration is required.

Validation: 10 Node tests; full MySQL/Redis suite 206 tests; SQLite suite 200 passed with six
MySQL-only skips. Coverage gates, app/domain PHPStan and Pint pass. The actual BerryProtocol
SDK loaded and produced a live QR. A temporary isolated Brave (Chromium) profile passed real
CDP launch/evaluate/background/cleanup checks; Chrome is not installed on this Mac, so set
CHROME_PATH to the Brave executable here. No permanent process-manager services were installed.
Live WhatsApp QR linking was rejected by the phone with “try again later”; phone-number pairing
is being attempted with the user. No successful real OTP delivery is claimed at this point.
See services/README.md for deployment, pairing, timeout and acceptance details.

Phase 6 pairing follow-up: the phone-number attempt also failed on the sender phone, and the
sidecar returned DISCONNECTED. No OTP was sent. Corrected saved-credential detection to require
`registered: true`; an unlinked creds.json must not cause automatic restart/reconnect attempts.
Added an allowlisted disconnect reason to authenticated status for subsequent diagnostics and
regression coverage for incomplete/corrupt auth files. The live pairing failure's cause is not
established; official WhatsApp Web pairing with the same sender is the next diagnostic check.

The user subsequently confirmed successful linking with the sender account in the browser;
Paykr itself remained disconnected. A local pairing retry exposed a closed-socket logout
exception that prevented credential cleanup and a fresh session. Disconnect now still clears
local auth after remote logout fails, with a regression test. An old remote linked-device entry,
if present, can be removed on the sender phone. Sanitized server diagnostics record error codes
and stack locations, excluding error messages, request bodies and credentials.

Live WhatsApp follow-up: sender +91 …6039 successfully linked to the Paykr sidecar by phone-number
code. A single test OTP was sent through Laravel WhatsAppOtp to the user-authorized recipient
+91 …0473; the sidecar returned delivered=true, queued=false. Local WHATSAPP_SIDECAR_ENABLED
is now true. Handset receipt confirmation is still pending; Google Messages live Android
acceptance remains pending. No OTP value or pairing credential is stored in these notes.

The user confirmed receipt of the real WhatsApp test OTP. WhatsApp manual acceptance is passed:
phone-number linking, Laravel dispatch, SDK send and handset receipt were verified. The current
sidecar regression suite passes all 12 tests. Google Messages live Android pairing/extraction
remains pending, and its feature flag remains disabled.

Google Messages manual pairing follow-up: the dedicated profile was on `/web/welcome` and
Google account sign-in, not the conversation inbox. Corrected the observer's URL check so
welcome/authentication pages cannot report CONNECTED or disappear offscreen before pairing.
Google sign-in is brought onscreen when restoring an unfinished background session. Added a
regression test for welcome/authentication/account-login states (five Messages tests pass).
Browser launch now recovers a live browser process with no remaining page tabs. The reopened
Demo Store profile is awaiting account sign-in and phone pairing; SMS extraction is not yet
verified and the Laravel feature flag remains disabled.

Google Messages live acceptance is blocked by Google's “This browser or app may not be secure”
sign-in rejection in the dedicated automated Brave profile. The user reports an existing signed-in
browser session; it is a separate profile and the current CDP sidecar does not attach to ordinary
browser tabs. Stopped the failing Messages sidecar and its owned browser; WhatsApp remains running.
Do not claim Google Messages live acceptance. Google's supported-browser documentation explicitly
lists software-controlled browsers among possible rejected sign-in environments. The existing
Android observer is an alternative for SMS intake without Google browser pairing.

## Phase 6 acceptance with Android SMS replacement — 2026-10-18

Phase 6 is ready to close for the user-selected setup: WhatsApp OTP delivery passed on the real phone, and the reviewed Android observer replaces Google Messages for live SMS collection. Google Messages browser extraction remains unverified and disabled, not silently marked passed. Earlier sidecar implementation and degraded-mode checks remain recorded above.

Android live acceptance includes phone pairing, signed heartbeat, genuine ₹1 bank SMS extraction/attribution and successful payment finalization. Offline retry delivered the diagnostic event after reconnection and the user confirmed the phone queue returned to zero. Automatic worker, clock and scheduler finalization/recovery passed isolated independent-process checks. Late/ambiguous credits are now retained for review and cannot automatically pay a newer competing order. Merchant Credit Reviews supports owner/manager approval and explicitly confirmed reopening of closed orders; this is an early, narrow Phase 8 addition, not completion of all merchant panels.

Latest verification: 213 tests passed, six MySQL-specific tests skipped in the SQLite run; application and strict domain static analysis passed. Temporary tunnel, loopback dashboard and foreground workers are development services, not production deployment. Broad OEM/reboot endurance and production distribution remain operational follow-ups. Next planned phase is Phase 7 (Reverb channels, after-commit broadcasts, checkout live updates and polling fallback).

## Phase 7 complete — authenticated real-time updates — 2026-10-18

Implemented private payment and merchant channel authorization, after-commit domain-event
adapters and queued Reverb broadcasts for all five §7 event names. Payment subscriptions
require the bound active device token or a valid customer token bound to that payment;
merchant subscriptions require active tenant membership. Authorization never creates or
rebinds a device, including for terminal payments. Payloads explicitly allowlist status fields,
not raw SMS, customer details or credentials. Client-originated events are disabled by default.
Legacy SSE endpoints retain their HTTP 410 migration hint.

Added a minimal `/checkout/{id}` status shell and reusable Pusher-protocol client, plus merchant
panel Livewire refresh integration. Clients refresh on subscription/reconnect/events, poll every
five seconds while disconnected or unauthorized, and reconcile every thirty seconds while
connected to recover missed deliveries. Credit Reviews also polls for newly held evidence.
The full checkout interface remains Phase 9; remaining Filament panels are next in Phase 8.

Verification: 224 tests passed, six MySQL-only tests skipped in the SQLite suite, 2,407 assertions.
Coverage gates pass (94.7% overall, 95.35% domain); application and strict domain PHPStan pass;
Pint and Vite production build pass using Node 22. Eleven realtime feature cases cover private
auth/isolation/revocation, each broadcast topic, rollback suppression and delivery-failure
resilience. The isolated real-browser check passed live delivery through Redis/Reverb,
blocked WebSockets with measured five-second polling, and offline/reconnect state refresh.
These tests use synthetic orders and do not modify the real confirmed payment.

Local Reverb is running at 127.0.0.1:8080; the payments worker and clock were reloaded.
The existing supervisor template already includes Reverb. These foreground local services
remain development services; production WSS routing and hosting are deployment work.
Commands and client credential-storage conventions are documented in deploy/README.md.

## Phase 8 — merchant and platform panels

Implemented the Filament merchant live monitor, counter desk, transactions, receiving
accounts, branding/phone preview and logo upload, payment methods, observers, Android
pairing/parse-only tools, Gmail connection, webhooks/API keys, payment simulator, customers,
support tickets and team management. Existing credit review remains available. Operational
pages receive live updates; configuration forms are not refreshed by payment events.

Added platform overview/observer widgets, merchant management and bounded support
impersonation, customer bans/evidence, payments, webhook deliveries, audit history,
WhatsApp sender controls, system health and platform defaults/APK publishing. Admin TOTP
remains mandatory. Platform defaults now feed public branding and OTP rate/attempt limits;
OTP expiry remains five minutes. The additive platform_options migration is applied locally.

All panel mutations use domain services and record actual actor/IP. Roles are checked on
each Livewire request, tenant queries fail closed, busy accounts cannot be deleted, and the
last owner cannot be removed. Support impersonation keeps the real admin identity in audits.
Browser testing exposed a Livewire lifecycle issue: persistent middleware runs before the
component action, so tenant/audit context must remain available in its scoped request.
Regression tests now cover that behavior in addition to cross-merchant action denial.

The full PHP suite passes: 276 tests, six MySQL-only skips, 2,661 assertions; overall coverage
93.4%, domain coverage 95.17%. Application and strict domain PHPStan pass. Pint and the Vite
production build pass. Panel browser verification uses temporary SQLite fixtures and no
outbound messages or real payment mutations; see deploy/README.md for the command.

New team accounts receive an owner-shareable password-setup invitation URL; email delivery
is not automatically triggered. Existing accounts retain their passwords. Gmail and WhatsApp
panel controls have mocked service coverage; no new live provider pairing was performed.
The local foreground services remain development services. Full React checkout and paykr.js
are Phase 9; production deployment is not part of this phase.

Final browser result: merchant page rendering, branding persistence and counter confirmation
passed; cashier restrictions passed; administrator TOTP login and all eleven platform pages
passed. The browser-created counter audit was independently checked in the temporary database
for the actual owner ID and 127.0.0.1 IP. Final focused panel suite: 73 tests, 386 assertions.
Local `/merchant/login` and `/admin/login` return HTTP 200 on port 8012.

## Phase 9 — React checkout and browser SDK

Replaced the minimal status shell with a responsive React/TypeScript checkout:
OTP sign-in/sign-up, remembered accounts, branded UPI QR and server-aligned timers,
processing, queue promotion, COD/counter, terminal states, help/cancel, duplicate-device
restart and fraud notice. Payment Done becomes available after one minute on desktop
and mobile. Device registration uses a non-extractable WebCrypto key in IndexedDB.
Public page bootstrap excludes customer credentials and private payment metadata.

Ported the browser SDK with Paykr/PaykrCheckout aliases and existing button options,
added X-Merchant-Key compatibility, exact iframe origin/source validation, and safe
HTTP(S) redirects. The build generates a minified SDK and SHA-384 integrity manifest;
the merchant developer panel displays the integration snippet. CORS preflight does
not bypass publishable-key origin authorization. Frame embedding is restricted to
merchant origins. Production hosting and provider onboarding remain separate work.

Verification: 279 PHP tests passed, six MySQL-only tests skipped, 2,694 assertions;
93.4% overall coverage and 95.15% domain coverage. Application/domain PHPStan,
TypeScript and production asset/SDK builds pass. Browser fixtures cover desktop and
mobile SDK checkout through OTP, QR, synthetic signed Android credit, success and
HMAC-verified webhook; COD, counter, two-customer queue promotion, cancellation during
verification grace, late credit, authenticated duplicate-device restart and fraud notice.
No real payment, phone message or provider pairing is performed by these tests.

Delayed/unmatched credits remain held for merchant review. Expired or cancelled orders
are not automatically reopened; explicit merchant confirmation remains required.
The test-only control/router scripts are guarded by testing environment and disposable
SQLite database checks and do not add production HTTP endpoints. CI builds vendor CSS
only after Composer installation and runs the checkout browser acceptance harness.
Next is Phase 10, following the migration build prompts.

Final React realtime regression passed live updates, blocked-WebSocket five-second polling
and offline reconnection against isolated Redis/Reverb fixtures.

## Phase 10 — hardening and security review

Implemented authenticated-key/IP throttling, fail-closed production runtime settings,
checkout script/connect/object/base/form CSP, strict URL validation for embedding origins,
and a catalogued HTTP 419 response for CSRF failures. Existing encrypted secrets, hashed
tokens, OTP limits, tenant scoping, TOTP, signed observer intake, webhook SSRF pinning and
real-actor auditing were reviewed against spec §16 and §19.2; linked evidence is in
PARITY.md and review scope/findings are in docs/SECURITY-REVIEW.md.

The isolated MySQL suite passed 11 tests / 480 assertions, including 50 simultaneous
customers on one account and 200 payment creations across 20 merchants within one minute.
The full desktop/mobile checkout matrix passed under CSP, as did live Reverb delivery,
blocked-WebSocket polling and reconnect. Composer and all three npm production dependency
audits returned zero advisories. No real transfers or provider messages were sent.
The load result measures local application/database behavior, not deployed HTTP capacity.
Next phase is data migration and cut-over preparation; no production switch was made.

Final gates: 291 PHP tests passed (six MySQL-only skips in the SQLite run), 3,011
assertions, 93.4% overall and 95.20% domain coverage. The separate MySQL run passed;
application/domain PHPStan and Pint pass. Phase 10 is complete within the local review
and test scope above. Phase 11 is data migration and cut-over preparation.

## Phase 11 — first-launch preparation and local integration rehearsal

Owner confirmed Node was never production and explicitly omitted historical import and
Node cut-over. Existing Laravel data/configuration/pairing were preserved. Owner selected
an isolated local rehearsal instead of a hosted staging deployment.

Added dependency-free merchant SDK 2.0.0 with secret-key authentication, legacy fullName
normalization, idempotency, authenticated status queries, exact raw-body HMAC verification
and generated browser alias exports from the existing reviewed SDK. Built an installable
private tarball and SHA-256 manifest; no public registry publication was performed.

Added a separate Node/SQLite coin-wallet example with a usable checkout button, server-side
payment verification, signed matching-order fulfilment, idempotent credits and persistent
wallet state. Browser amounts/coins, unknown orders, pending payments and unverified success
URLs cannot grant coins. Legacy unsafe popup-credit assertions are replaced by rejection
checks. The demo is loopback-only and single-wallet, not a production customer store.

Real Laravel SDK create/query/idempotency and store-to-checkout browser navigation pass in
a disposable database. Wallet verification, amount mismatch, duplicate credits and restart
persistence pass. Node, PHP, Python and Go HMAC examples pass valid/tampered/malformed/wrong
signature checks. The full PHP suite remains 291 passed, six MySQL-only skips, 3,011 assertions.
Build, package tests and formatting pass; CI includes the new integration checks.

First-deployment/backup/pairing/rollback instructions are in docs/CUTOVER.md. No database
reset, real payment, provider message, device re-pairing or production switch was performed.
Hosted HTTPS/provider acceptance remains a first-launch gate, as agreed with the owner.

Final local rehearsal: the full desktop/mobile checkout matrix passed again, including
COD/counter, queue promotion, cancellation during grace, late-credit review, duplicate-device
restart and fraud blocking. The scoped Phase 11 local work is complete; hosted launch and
registry publication remain separate future release actions.

## Separate training sections and integration guide

Organized merchant (27), administrator (25), and integration (17) lessons as 69 separate
named MP4s. Added a seven-minute English narrated/captioned integration guide covering
browser SDK, backend order creation, webhook validation, atomic fulfilment, testing and
in-dashboard training deployment. Full guides, chapter clips, transcripts and a combined
sharing ZIP remain in private storage. The curated deployable media ZIP excludes source
captures and credentials.

Added merchant Help → Training and admin Training pages. Videos are served from private
storage using expiring signed URLs plus session/role checks; merchants cannot fetch admin
videos. Binary responses support byte ranges. Full regression: 293 passed, six MySQL-only
skips, 3,028 assertions; 93.2% overall and 95.20% domain coverage. PHPStan and Pint pass.
Isolated browser verification covers actual video playback and merchant/admin separation.
See docs/TRAINING.md for installation and updates. No production data or provider pairing
was changed, and no training content was sent to external recipients.
