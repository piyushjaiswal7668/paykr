# Standalone coin wallet demo

This is an optional loopback-only, single-wallet integration example, not a multi-user
production store. Node 22.13+ is required for built-in SQLite. It starts with 50 demo coins.
Top-ups cost ₹1–₹10,000, with one coin per whole rupee. The server calculates coins; browser
metadata is never trusted. SQLite stores orders and credited flags across restarts.

From this directory, set PAYKR_API_URL, PAYKR_SECRET_KEY and PAYKR_WEBHOOK_SECRET in your
shell/environment manager, then run `node server.mjs`. Default address is
`http://localhost:5000`; STORE_URL, PORT and WALLET_DATABASE override local defaults.
Keep the secret key server-side. Configure the merchant webhook URL to reach
`/api/webhook` on this store. Production Laravel deliberately rejects loopback webhooks;
use a separate local testing environment for this demo, or deploy a secured store on HTTPS.
Do not change the existing phone pairing or merchant keys simply to run acceptance tests.

The home-page button creates a payment through Laravel and opens checkout. The return
page rechecks payment server-side. Signed SUCCESS webhooks and getPayment SUCCESS can
credit only a matching stored order, and SQLite's conditional update prevents duplicates.
Unknown orders, invalid signatures, incorrect amounts and browser-forged credits fail.
No API/checkout reverse proxy is needed: the SDK uses Laravel's canonical URLs.

Run `php tests/Support/check-merchant-demo.php` from the repository root for disposable
fixtures on ports 8022/8023, with PAYKR_NODE_PATH / PAYKR_BROWSER_PATH overrides if needed.
The test uses synthetic webhook signatures; it sends no real messages or payments.
