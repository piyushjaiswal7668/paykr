# Integrate Paykr into your website or app

Paykr is self-hosted. You need an operational Paykr deployment, a configured merchant and a
working payment-evidence source. Merely generating a UPI QR does not confirm bank receipt.

## 1. Configure the merchant

In the merchant dashboard, configure the receiving UPI account/payee, exact allowed website
origins (scheme + hostname + port), and a webhook URL on your merchant backend. Production
webhooks must use a publicly reachable HTTPS endpoint; loopback/private destinations are rejected.
Pair the authorized Android observer or configure another supported evidence source.

Generate an API key and configure a webhook signing secret through the merchant dashboard.
Keep the secret API key and webhook secret exclusively on the merchant server. Publishable
keys are for browser SDK use and require an allowed Origin. Never expose secret keys in HTML,
mobile app bundles or frontend environment variables.

## 2. Install the server SDK

In your Paykr checkout, after Composer/Node dependency installation:

```sh
npm run build
npm pack ./packages/merchant-sdk
```

Copy the generated tarball to your merchant backend, then install it there:

```sh
npm install ./paykr-merchant-sdk-2.0.0.tgz
```

The example assumes the current version 2.0.0; use the filename produced by npm pack.
The package is not assumed to exist on the public npm registry. Node 22.13+ is recommended.

Set server-only environment values:

```dotenv
PAYKR_API_URL=https://payments.your-domain.example
PAYKR_SECRET_KEY=<merchant secret key>
PAYKR_WEBHOOK_SECRET=<merchant webhook secret>
```

## 3. Create a payment on your backend

```js
import { PaykrPaymentClient } from '@paykr/merchant-sdk';

const paykr = new PaykrPaymentClient({
  apiUrl: process.env.PAYKR_API_URL,
  secretKey: process.env.PAYKR_SECRET_KEY,
  webhookSecret: process.env.PAYKR_WEBHOOK_SECRET,
});

// order comes from your authenticated server-side order database.
// Prices and currency are calculated by the server, not supplied by the browser.
const payment = await paykr.createPayment({
  orderId: order.id,
  amount: order.totalRupees, // decimal rupee string, e.g. "149.00"
  currency: 'INR',
  idempotencyKey: order.paymentAttemptKey,
});

// Persist payment.paymentId against this order before redirecting the customer.
// Reuse the attempt key on network retries; allocate a new one for a new attempt.
// Return payment.checkoutUrl to your frontend and navigate the customer there.
```

This is a framework-neutral excerpt: implement authentication, order lookup, database writes,
error handling and redirects in your own backend. The full runnable reference is
[examples/coin-wallet-store](../examples/coin-wallet-store/README.md).
For an embedded browser checkout, use the script snippet and integrity value shown on the
merchant API Keys page. Update integrity values whenever deploying a changed SDK.

## 4. Verify webhooks before fulfilment

Capture the untouched request-body bytes before any JSON body parser. Verify them with the
`X-Gateway-Signature` header:

```js
// rawBody is the original Buffer; header is from the incoming HTTP request.
const event = paykr.verifyWebhook(rawBody, request.headers['x-gateway-signature']);
```

Reject missing/invalid signatures. After verification, use a database transaction to:

1. Find your stored order and its linked payment attempt.
2. Match event.orderId, event.paymentId, event.currency and event.amountPaise exactly.
3. Fulfil only event.event === 'SUCCESS'. Other states do not authorize goods or wallet credit.
4. Atomically mark the order fulfilled and grant goods once. Use a unique database constraint
   or conditional update, not just an in-memory flag. Handle a later SUCCESS after a reviewed
   expired/cancelled payment without granting the order twice.
5. Persist completion before returning HTTP 2xx. Duplicate deliveries should return 2xx without
   repeating fulfilment. Temporary processing failures should return a retryable failure.

Webhooks are at-least-once. X-Gateway-Delivery-Id is a useful delivery identifier, but order-level
idempotency remains necessary. Signature validation alone does not match a payment to your order.
See the [Node example](../examples/coin-wallet-store/server.mjs) and standalone verification
examples for [PHP](../examples/webhooks/verify.php), [Python](../examples/webhooks/verify.py)
and [Go](../examples/webhooks/verify.go).

## 5. Reconcile on your backend

```js
const state = await paykr.getPayment(storedPaymentId);
```

Match orderId, paymentId, currency and amountPaise against your database, and require
state.status === 'SUCCESS' through the same atomic fulfilment operation. Browser callbacks,
return URLs, a customer-entered UTR or screenshots must never independently grant goods.
Paykr SUCCESS reflects its configured evidence/review pipeline, not direct bank API proof.

## 6. Test before accepting customer payments

Run the isolated example check from the Paykr repository:

```sh
php tests/Support/check-merchant-demo.php
```

Install Playwright Chromium if needed (`npx playwright install chromium`). The check uses
synthetic evidence and temporary databases; it does not transfer money. Verify successful,
failed, expired, duplicate and late-credit review paths, invalid signatures and webhook retries.
Then perform an authorized small real payment on your own configured deployment and verify
bank receipt, Paykr status and exactly one fulfilment. Keep all required workers running.
