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
- [Contribute](CONTRIBUTING.md) · [Report a vulnerability privately](SECURITY.md)

## Local setup

Requires PHP 8.3+ (intl, mbstring, PDO MySQL/SQLite, bcmath, pcntl), Composer,
Node 22.13+, MySQL 8 and Redis 7. SQLite is used for isolated tests; the application uses MySQL.

```sh
git clone https://github.com/piyushjaiswal7668/paykr.git
cd paykr
composer install
cp .env.example .env # first setup only; never overwrite an existing environment
php artisan key:generate
npm ci
npm run build
```

Create a dedicated MySQL database and user. Set DB credentials, APP_URL and Redis settings
in `.env`. Set PAYKR_ADMIN_EMAIL and a unique PAYKR_ADMIN_PASSWORD (12+ characters),
PAYKR_INTERNAL_TOKEN (32+ characters), REVERB_APP_KEY and REVERB_APP_SECRET.
Generate separate random values, for example with `openssl rand -hex 32`.
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
