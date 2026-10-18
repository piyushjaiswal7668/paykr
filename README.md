# Paykr

Laravel 12 / Filament 5 migration of the Node gateway at `/Applications/XAMPP/xamppfiles/htdocs/YouPI-PG`.
The specification and phase prompts are in [laravel-migration](laravel-migration/00-README.md).

## Requirements and setup

PHP 8.3 with intl, mbstring, PDO MySQL, bcmath and pcntl; Composer; MySQL 8; Redis 7.
Use a PHP runtime with PCOV or Xdebug for the required coverage checks.

```sh
composer install
cp .env.example .env # only on first setup; preserve an existing .env
php artisan key:generate
```

Configure DB credentials and Redis in `.env`. Set a unique `PAYKR_ADMIN_PASSWORD` (12+ characters),
`PAYKR_INTERNAL_TOKEN` (32+ characters), and Reverb app key/secret. Then:

```sh
php artisan migrate --seed
php artisan serve
```

Visit `/admin` and sign in using `PAYKR_ADMIN_EMAIL` / `PAYKR_ADMIN_PASSWORD`; enroll an authenticator
before accessing the dashboard. Local/testing seeds an empty demo merchant and links the admin as its
owner, making `/merchant` available with the same credentials. The panels use separate sessions.
Production seeds no demo merchant. Re-running the seeder does not reset an existing password or 2FA.

The current local `.env` already has generated secrets, including an admin password; read them locally.
No Node project files or database have been modified.

Run workers separately when the services are available:

```sh
php artisan horizon
php artisan paykr:clock
php artisan reverb:start
php artisan schedule:work
```

Serve only `public/` as the document root. Production needs HTTPS and `SESSION_SECURE_COOKIE=true`.
The application timezone is UTC; queue, cache and session defaults are Redis. `/health` is currently
an application-liveness endpoint with a separate `clock.healthy` worker-readiness field.

## Verification

```sh
composer test       # Pest, architecture, API contracts, 80% overall / 90% domain coverage
composer analyse    # Larastan level 6, domain level 8
vendor/bin/pint --test
composer test:quick # fast suite without coverage; does not replace the phase gate
```

The default test database is disposable in-memory SQLite. CI runs the same suite with MySQL 8 and Redis 7.
Never point tests at a development or production database: the test suite rebuilds it.
The MySQL queue concurrency test requires a database name ending in `_test`; it launches
50 independent PHP workers. SQLite explicitly skips this row-locking test.

See [migration notes](MIGRATION_NOTES.md) for phase status and deviations,
and [the parity ledger](docs/PARITY.md) for all 109 routes and 249 legacy tests.

## This machine's MySQL connection

MySQL 8.4 runs as the Homebrew service `mysql@8.4`, bound to `127.0.0.1:3306`.
In MySQL Workbench, add a Standard TCP/IP connection with username `paykr`, schema `paykr`,
and the `DB_PASSWORD` from `.env`. Migration and seeding have already run.
The `paykr_test` database is isolated for tests; do not use it for application data.

```sh
brew services info mysql@8.4
brew services stop mysql@8.4
brew services start mysql@8.4
```

Redis is installed and running as the Homebrew `redis` service at `127.0.0.1:6379`.
It binds to loopback only, with protected mode enabled. Laravel uses Predis; no PHP Redis extension
is needed. Cache, sessions, Horizon startup, and both login pages have been verified.

```sh
redis-cli ping # PONG
brew services info redis
brew services stop redis
brew services start redis
```

MySQL and Redis start at login. Run `php artisan serve` and `php artisan horizon` separately when
developing; the temporary verification processes have been stopped.

## Legacy SQLite import

Phase 1 is complete. The development database is migrated and seeded; a verified legacy copy is
available separately in `paykr_import_check`. See `MIGRATION_NOTES.md` for counts and compatibility notes.

```sh
php artisan paykr:import-sqlite /absolute/path/to/paykr.sqlite
```

Run this only against an **empty destination domain database**. Configure that database through the
process environment or a dedicated local environment file first. Running it against the seeded `paykr`
database deliberately fails without changing data. The source is opened read-only, the destination
import is transactional, and existing records are never overwritten. Do not run the normal demo
seeder in a fresh destination before importing. Device identifiers and token handling are described
in the migration notes; HTTP parity continues in later phases.


Terminal payments now persist signed merchant webhooks in the payment transaction. Run Horizon for
the Redis delivery jobs. To recover due deliveries after a queue outage, run:

```sh
php artisan paykr:webhooks:retry-pending
```

This command queues real merchant deliveries; tests use fake HTTP receivers. The scheduler runs recovery every minute. Merchant receivers must deduplicate the
stable `X-Gateway-Delivery-Id`; HTTP delivery is at-least-once.


Payment verification now uses persisted L1 grace deadlines and multi-channel L2 attribution.
Horizon processes grace jobs. After a queue outage, recover due verification work with:

```sh
php artisan paykr:verification:recover
```

This can finalize eligible payments and queue their merchant webhooks. The clock finalizes due grace deadlines each second, and the scheduler also requeues them every minute. See the migration notes for the remaining Phase 2 work.


Phase 2 core domain services are complete, including manual bans/unbans, evidence queries,
device-token access checks and merchant UPI health. Public API and panel wiring remain in their
later phases. Phase 3 now adds the clock worker, delayed expiry jobs and startup recovery.


Phase 3 is implemented. Run `php artisan paykr:clock` alongside Horizon and the scheduler.
The clock uses a renewable Redis lock and persisted MySQL deadlines; startup recovers overdue
payments and requeues future expiry/grace jobs. `/health`, `/api/v1/health` and the admin System
page show its heartbeat and recovery counts. `--once` performs one recovery pass and exits.

Horizon now serves `payments`, `webhooks`, `observers`, then `default`. Restart an existing Horizon
process after updating. Gmail polling uses merchant credentials and the real verification pipeline;
Google Messages sweeps and WhatsApp dispatch are disabled unless explicitly configured. Phase 6
sidecar packages and setup instructions are in [services/README.md](services/README.md). Example process configs are in [deploy](deploy/README.md); no system
services have been installed or left running by the migration tests.

Phase 4 public API routes are implemented: merchant payments, customer OTP/password sessions,
device registration/verification and checkout actions. Integration requests use `X-Api-Key` (or a
bearer API key); publishable keys also require an allowed `Origin`. Checkout requests send
`X-Device-Id` and `X-Device-Token`; binding, retry and cross-device restart also need the customer's
bearer session. Payment reads require a secret key. Legacy merchant-ID authentication is off by
default. See [migration notes](MIGRATION_NOTES.md) for compatibility changes and validation.

The React checkout shell, SDK and Reverb transport are separate later phases. Phase 5 below adds Android observer authentication/enrollment, Gmail and internal observer endpoints.

Phase 5 adds Android observer enrollment/intake, protected internal observer endpoints and Gmail
OAuth. After signing into a permitted staff account, open
`/merchant/gmail/connect?merchantId=<merchant UUID>` to authorize that merchant's mailbox. Configure
`GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET` and the exact `GMAIL_REDIRECT_URI` first. The connection
uses Google's [web-server OAuth flow](https://developers.google.com/identity/protocols/oauth2/web-server)
and [Gmail profile endpoint](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/getProfile).
The callback never displays tokens. Test/disconnect are authenticated POSTs with normal web CSRF
protection; the Filament settings UI is added in its later phase.

Authenticated staff can issue enrollment QR data with
`POST /api/v1/merchant/{merchantId}/observers/pairing-token`. Install the original APK from
`/paykr-sms-gateway.apk`; the server URL in the QR must be reachable from the phone. See
[migration notes](MIGRATION_NOTES.md) and the [driver fixture notes](tests/Fixtures/android/README.md)
for contract tests and compatibility details. A live phone and Google account have not yet been
used for manual acceptance. Phase 6 adds the WhatsApp and Google Messages Node sidecars; see [their setup guide](services/README.md).
