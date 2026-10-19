# Paykr

Self-hosted UPI payment orchestration for merchant websites and apps, built with Laravel 12,
Filament 5, React, MySQL and Redis. Includes merchant/admin dashboards, checkout, a Node SDK,
signed merchant webhooks and an Android bank-message observer.

**Paykr currently verifies payment evidence from configured observers and merchant review.
It does not provide direct bank API confirmation.** SMS/email delivery can be delayed and
ambiguous credits require review. Open-source software does not remove hosting, messaging,
provider or operational costs. Validate your deployment before accepting customer payments.

- [Connect your website or app](docs/INTEGRATION.md)
- [Deploy and run workers](deploy/README.md)
- [Android observer source and build](android-observer/README.md)
- [Optional WhatsApp / Messages services](services/README.md)
- [How our repository-owned CI works](docs/CI.md)
- [Contribute](CONTRIBUTING.md) · [Report a vulnerability privately](SECURITY.md)

## Local setup

Requires PHP 8.3+ (intl, mbstring, PDO MySQL/SQLite, bcmath, pcntl), Composer,
Node 22.13+, MySQL 8 and Redis 7. SQLite is used for isolated tests; the application uses MySQL.
Use Linux or macOS; on Windows use WSL2 because Horizon and the clock require PCNTL.
Install and start MySQL and Redis before continuing. This is a self-hosted application,
not a one-click hosted service: real payments also need a receiving account, HTTPS,
observer pairing and customer OTP delivery.

Check your installed tools:

```sh
php --version
composer --version
node --version
mysql --version
redis-cli ping # should return PONG
```

```sh
git clone https://github.com/piyushjaiswal7668/paykr.git
cd paykr
composer install
test -f .env || cp .env.example .env # preserves an existing environment
php artisan key:generate
npm ci
npm run build
```

Create a dedicated database using a MySQL administrator session. Replace the password
placeholder before running this SQL; it must match DB_PASSWORD in your `.env`:

```sql
CREATE DATABASE paykr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'paykr'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON paykr.* TO 'paykr'@'127.0.0.1';
```

Edit `.env` before running migrations:

| Setting | Local value / action |
| --- | --- |
| `APP_URL` | `http://localhost:8000` |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` |
| `DB_DATABASE`, `DB_USERNAME` | `paykr`, `paykr` |
| `DB_PASSWORD` | Your database user's password |
| `REDIS_HOST`, `REDIS_PORT` | `127.0.0.1`, `6379` |
| `PAYKR_ADMIN_EMAIL` | Your administrator login email |
| `PAYKR_ADMIN_PASSWORD` | A unique password of at least 12 characters |
| `PAYKR_INTERNAL_TOKEN` | A separate random secret of at least 32 characters |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Generate a separate random value for each |

Run `openssl rand -hex 32` separately for each random value. Keep the generated APP_KEY.
Never commit `.env`, signing keys, customer messages or database dumps.

```sh
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Open http://localhost:8000/admin and sign in with your configured admin credentials; enroll
an authenticator. In local/testing environments the seeder also creates a demo merchant,
accessible at `/merchant` with the same credentials. Its placeholder UPI address is not a
real receiving account. Production does not seed a demo merchant. Re-running seeds does
not reset passwords or 2FA.

Run each worker in a separate terminal:

```sh
php artisan horizon
php artisan paykr:clock
php artisan reverb:start
php artisan schedule:work
```

Horizon processes payments, webhooks and observer jobs. The clock enforces payment deadlines;
the scheduler recovers missed jobs. Configure your receiving account and an authorized observer
before testing a payment. Android pairing requires a trusted HTTPS URL reachable by the phone.
Customer OTP delivery requires the configured messaging service; see the service guide above.

### Confirm the installation

- Open `/admin`, sign in and complete authenticator enrollment.
- Open `/merchant` and confirm the demo store dashboard loads.
- Open `/health` after starting the workers; `clock.healthy` should be true.
  HTTP success alone only shows the application is alive.
- Follow the integration guide below for synthetic checkout testing before real transfers.

### Common setup problems

| Symptom | Check |
| --- | --- |
| Database connection refused / access denied | MySQL is running; database/user exist; `.env` host and password match the SQL setup. |
| Redis connection refused | Start Redis and confirm `redis-cli ping` returns `PONG`. |
| Missing Vite manifest or unstyled pages | Run `composer install`, `npm ci`, then `npm run build`. |
| Missing PHP extension | Install/enable the named extension for the CLI PHP shown by `php --version`. |
| Payment stays pending / webhook not delivered | Keep Horizon, the clock and scheduler running; inspect observer status and held-credit review. |
| Phone cannot pair | Use a trusted HTTPS URL reachable from the phone; the phone's localhost is not your computer. |
| Customer OTP not received | Configure and pair the WhatsApp service; it is disabled in the example environment. |
| Changed `.env` but old values remain | Run `php artisan optimize:clear`, then restart workers and the server. |

## Add payments to a website

1. Configure the merchant's receiving account, allowed website origins and webhook endpoint.
2. Generate API keys in the merchant dashboard; keep the secret key on your backend.
3. Create a Paykr payment from a server-priced order and send the customer to `checkoutUrl`.
4. Verify signed webhooks and match the stored order before fulfilling it exactly once.

Start with the [integration guide](docs/INTEGRATION.md) and the runnable
[coin-wallet example](examples/coin-wallet-store/README.md). PHP, Python and Go webhook
verification examples are in [examples/webhooks](examples/webhooks).
The Node SDK is built from source; registry publication is not assumed.

## Verification

```sh
composer test:quick
composer test       # requires PCOV/Xdebug; checks overall and domain coverage
composer analyse
vendor/bin/pint --test
npm run typecheck
npm run build
npm test --prefix packages/merchant-sdk
```

Tests use disposable SQLite by default. CI also runs MySQL 8/Redis 7 checks. MySQL concurrency
tests require a database ending in `_test` and rebuild it: never use development/customer data.
See [security review](docs/SECURITY-REVIEW.md) for verification scope and limitations.

## Deployment

Serve only `public/` over HTTPS, disable debug, configure secure sessions, and run supervised
workers. Keep `/internal/*`, sidecars, MySQL and Redis private. Back up the database and APP_KEY
securely. Follow [deployment instructions](deploy/README.md) before enabling real payments.
No hosted payment service or production availability guarantee is provided by this repository.

## Contributing and ownership

Contributions are welcome through forks and pull requests targeting `main`. Public contributors
can submit changes but cannot push or merge into this repository. The repository owner reviews
and merges contributions. See [CONTRIBUTING.md](CONTRIBUTING.md).

Project source is available under the [MIT license](LICENSE). Third-party dependencies and
brand assets retain their own licenses and rights; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
Historical migration records are in [laravel-migration](laravel-migration/00-README.md) and
[MIGRATION_NOTES.md](MIGRATION_NOTES.md); dated phase notes are not current setup instructions.
