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
// Reuse the attempt key and stored request on network retries.
// Create a new attempt only after resolving the previous payment outcome.
// Return payment.checkoutUrl to your frontend and navigate the customer there.
```

This is a framework-neutral excerpt: implement authentication, order lookup, database writes,
error handling and redirects in your own backend. The full runnable reference is
[examples/coin-wallet-store](../examples/coin-wallet-store/README.md).
For an embedded browser checkout, use the script snippet and integrity value shown on the
merchant API Keys page. Update integrity values whenever deploying a changed SDK.

### Retry protection during deployments and temporary outages

Implement this on your **merchant backend**, not in the customer's browser. Paykr's SDK
sends `idempotencyKey` as the `Idempotency-Key` header, but does not automatically retry.

1. **Save first.** In a database transaction, save the order and a payment-attempt record
   before calling Paykr. Generate the attempt key once (for example, a UUID), and persist
   the exact JSON request body, state `CONNECTING`, retry count, and next retry time.
   Calculate prices on your server. Use a unique constraint and a transaction/row lock so
   double clicks, page refreshes, and concurrent workers reuse the same active attempt.
2. **Reuse the same request.** Every retry must use the same merchant, `Idempotency-Key`,
   and stored request body. Keep amount, order ID, customer, device ID (if supplied),
   return URL, metadata, and consent unchanged. Do not regenerate timestamps or other
   metadata during retries. A changed request with the same key is rejected.
3. **Retry temporary failures.** Retry a network timeout/connection failure or HTTP
   `502`, `503`, or `504` with bounded exponential backoff and jitter. Respect a valid
   `Retry-After` header. A timeout does **not** prove that Paykr rejected the request:
   Paykr may have created the payment before the response was lost.
4. **Keep the booking pending.** Show “Connecting to payment service…” while retrying.
   After automatic retries are exhausted, show “Payment setup is delayed. Please check
   again shortly.” Keep the attempt unresolved; do not mark the booking failed or start
   a new payment with a new key. A customer retry button should resume the saved attempt.
5. **Persist the result.** Save Paykr's `paymentId` and `checkoutUrl` before returning the
   checkout URL to the browser. If that local write fails, retry the same attempt to
   recover the result. Never treat successful payment creation as successful payment.

Suggested attempt fields: `order_id`, `idempotency_key` (unique), `request_json`, `state`,
`retry_count`, `next_retry_at`, `payment_id`, and `checkout_url`. These states belong to
**your website**; they are not new Paykr payment statuses. Store customer data securely
and do not log API keys or full request bodies.

The following Node backend helper performs **one delivery attempt**. It uses native
`fetch` so an HTML maintenance response can be classified by HTTP status before parsing
JSON. It does not replace your database, durable job queue, authentication, or ownership
checks. `attempt.request_json` must be the original saved JSON string; put the
idempotency key in the header, not in that JSON body.

```js
async function deliverPaymentAttempt(attempt) {
  // Keep apiUrl and the secret key in trusted server configuration.
  const apiUrl = process.env.PAYKR_API_URL.replace(/\/+$/, '');
  let response;
  try {
    response = await fetch(`${apiUrl}/api/v1/payments`, {
      method: 'POST',
      redirect: 'error',
      signal: AbortSignal.timeout(15_000),
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Api-Key': process.env.PAYKR_SECRET_KEY,
        'Idempotency-Key': attempt.idempotency_key,
      },
      body: attempt.request_json,
    });
  } catch {
    // Outcome is unknown. Configuration/TLS errors also need operator attention
    // if they persist; never disable certificate verification to make retries work.
    return { kind: 'retry', retryAfterMs: 0 };
  }

  if ([429, 502, 503, 504].includes(response.status)) {
    const header = response.headers.get('retry-after');
    const delay = header && /^\d+$/.test(header)
      ? Number(header) * 1000
      : header ? Date.parse(header) - Date.now() : 0;
    return {
      kind: 'retry',
      retryAfterMs: Number.isFinite(delay) ? Math.max(0, delay) : 0,
    };
  }

  let body;
  try {
    body = await response.json();
  } catch {
    // A missing/invalid success response is also ambiguous. Stop for reconciliation;
    // do not create a different attempt or infer that payment creation failed.
    return { kind: 'review', httpStatus: response.status };
  }
  if (!response.ok) {
    // Do not blindly retry authentication, validation, idempotency, device-binding,
    // or other application errors. Inspect the error and reconcile the same attempt.
    return { kind: 'review', httpStatus: response.status, code: body?.code };
  }
  if (typeof body?.paymentId !== 'string' || typeof body?.checkoutUrl !== 'string') {
    return { kind: 'review', httpStatus: response.status };
  }
  return { kind: 'created', payment: body };
}
```

In your durable worker, claim one saved attempt at a time. For `retry`, atomically
increment `retry_count`, persist `next_retry_at`, and release the job for later execution.
For example, allow five retries after the initial request, using delays of 1, 2, 4, 8,
and 16 seconds plus 0–500 ms jitter; use the longer of that delay and `retryAfterMs`.
Do not hold a database transaction open during the HTTP request or sleep inside a web
request. If a worker crashes after Paykr accepts the request, another worker must reclaim
its expired job lease and reuse the saved key/body. After the retry limit, keep the
attempt pending reconciliation and alert your operator rather than retrying forever.

For `created`, atomically attach the payment to the order and mark the attempt resolved.
If the returned session is already terminal, reconcile its payment status instead of
assuming a fresh checkout. For `review`, preserve the attempt and inspect the error.
Treat HTTP `429` as rate limiting: schedule a later retry of the same attempt according
to the rate-limit response instead of repeatedly making immediate requests.

Expose an authenticated order-status endpoint on **your website**, scoped to the order
owner. The browser can poll it for `CONNECTING`, delayed setup, or a saved checkout URL.
Do not expose the merchant secret key or let browsers select another customer's attempt.
Once `payment_id` is known, use Paykr's status/recheck APIs and signed webhooks to track
payment confirmation; do not call payment creation repeatedly to check whether money arrived.

Graceful deployments reduce interruptions but do not remove network timeouts, server
restarts, or exceptional maintenance windows. Keep this retry protection regardless of
the Paykr deployment strategy. An idempotency key prevents duplicate creation for that
attempt; it does not extend the payment deadline, reopen a closed payment, or prove payment.

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

### Returning from checkout

When creating a standalone checkout, set `returnUrl` to your website's order-status
page. Paykr returns there after success or confirmed cancellation. The return page
must fetch the payment status through your backend; arriving at that URL does not
mean a payment succeeded. Without `returnUrl`, checkout cannot determine where to
return the customer.

Embedded SDK checkout reports cancellation to the parent page. Configure the SDK's
`cancelledUrl` (or `data-cancelled-url`) for a separate cancellation destination.

### UPI payment options

Checkout displays the QR and a copyable receiving UPI ID with the recipient name and
exact amount. Tap the QR to enlarge it; the enlarged view closes when the payment is
no longer active. Customers can scan from another device or pay the copied ID manually;
checkout does not launch UPI apps through payment links. If clipboard access is blocked,
the read-only UPI field can be selected and copied manually.

“I’ve paid — check payment” moves an active UPI payment into the existing processing
window; it does not approve payment. Only verified evidence or authorized merchant
review can confirm receipt. Existing timeout and late-credit review rules still apply.
Manual payments without an order reference may require merchant review when attribution
is ambiguous; never fulfil an order based on the customer's button click.

### Delayed confirmation: recheck and request review

Merchant backends can call these endpoints with their **secret** API key:

- `POST /api/v1/payments/{paymentId}/recheck`
- `POST /api/v1/payments/{paymentId}/request-review`

Both return HTTP 200 on a successful check. `status` remains the actual payment state;
`verificationStatus` describes confirmation progress. For example, a payment whose
30-second processing window ended without bank evidence can return:

```json
{
  "paymentId": "original-payment-id",
  "orderId": "original-booking-id",
  "status": "FAILED",
  "verificationStatus": "AWAITING_EVIDENCE",
  "canRequestReview": true,
  "reviewId": null,
  "reviewStatus": null,
  "message": "Awaiting bank evidence. If you already paid, do not pay again."
}
```

Display “Confirmation delayed” for this result, not a claim that the bank rejected the
transfer. Do not fulfil the order or ask the customer to pay again based on this result.
The merchant website must authenticate the customer and check ownership of the booking
before forwarding either request with its secret key.

With the updated server SDK:

```js
const result = await paykr.recheckPayment(order.paykrPaymentId);
// If result.canRequestReview is true, offer an explicit “Request payment review” button.
// After the customer selects that button:
const review = await paykr.requestPaymentReview(order.paykrPaymentId, {
  reference: customerSuppliedUtr, // optional, at most 100 characters; NOT verified evidence
  note: customerExplanation,    // optional, at most 2000 characters
});
```

The checkout customer equivalents are `POST /api/v1/checkout/{id}/recheck` and
`POST /api/v1/checkout/{id}/request-review`. They require the authenticated payment
owner's customer session plus the checkout device headers (live sessions enforce device
proof). Merchant publishable keys cannot call the merchant-backend endpoints.

| verificationStatus | Meaning |
| --- | --- |
| `AWAITING_EVIDENCE` | No validated evidence is linked yet; a review can be requested. |
| `VERIFYING` | Linked evidence is still being verified; wait and recheck. |
| `REVIEW_REQUIRED` | Evidence is linked to a closed payment; merchant approval is required. |
| `REVIEW_REQUESTED` | An existing or new merchant support ticket is returned; inspect `reviewStatus` for its OPEN/IN_PROGRESS/RESOLVED/CLOSED state. |
| `CONFIRMED` | Payment status is SUCCESS. Use the usual server verification/fulfilment rules. |

Review creation rechecks eligibility atomically and returns the existing ticket on repeat
requests. If confirmation has completed, it returns CONFIRMED without creating a ticket.
While evidence is VERIFYING it does not create a review. Merchant requests appear in
**Support Tickets**; approving a late credit remains a separate **Credit Reviews** action
with explicit confirmation to reopen a closed order. Resolving a support ticket does not
mark the payment paid.

Rechecks apply existing expiry/grace rules and may finalize already validated linked
evidence. They do not query a bank API, resubmit SMS, guess matches for unassigned credits,
restart a timer, allocate a new payment slot, or create another booking. Both actions are
rate-limited to 10 requests per minute under Laravel's route throttle, in addition to
existing merchant limits; clients must respect HTTP 429 and Retry-After. New/queued,
non-UPI, and customer-less payments are not eligible (HTTP 400).

## WhatsApp notifications and website booking updates

Paykr can send WhatsApp updates to both the merchant and the customer, while your website receives signed webhook events. You do not need a second WhatsApp integration in your website. All three switches are **off by default**.

1. The platform administrator connects the WhatsApp sender in **Admin → WhatsApp Sender**. Keep its sidecar, the default queue worker, and scheduler running.
2. A merchant owner/manager opens **Operations → Notifications → Settings**, enters their ten-digit Indian WhatsApp number, and enables merchant alerts and/or customer alerts. Messages use the platform sender and identify the merchant and order.
3. Configure the website endpoint and signing secret under **Developer → Webhooks**. Update your receiver to handle the events below, then enable **Send workflow events to website** in Notifications settings.
4. Obtain explicit customer permission with an unchecked checkbox, for example: “Send payment and booking updates to my WhatsApp number through Paykr.” Store the consent in your own order record. Your server can then set `whatsappUpdatesConsent: true` when creating the payment. This option requires a secret API key and an associated customer; do not put the secret key in the browser.

```js
const payment = await client.createPayment({
    orderId: order.id,
    amount: order.total, // Read the amount from your server database.
    customer: { name: order.name, mobileNumber: order.phone },
    whatsappUpdatesConsent: order.whatsappConsent === true,
    returnUrl: `${storeUrl}/orders/${order.id}`,
});
```

For an existing payment, your authenticated server can call:

```js
await client.setWhatsappUpdatesConsent(paymentId, true); // Only after obtaining consent.
await client.setWhatsappUpdatesConsent(paymentId, false); // Customer opts out.
```

This calls `POST /api/v1/payments/{paymentId}/notification-preferences` with `{ "enabled": true|false }`. Customers can also call the checkout equivalent with their authenticated Paykr session. Consent is tied to that payment's phone number; changing the bound customer does not transfer consent. Already delivered messages cannot be recalled. New opt-in does not replay past events.

### Merchant verification and booking decisions

The merchant receives order/payment alerts with a link into their authenticated dashboard. For delayed payment evidence, use **Credit Reviews** to inspect the bank evidence and explicitly approve it; expired/cancelled payments require confirmation to reopen. A review request or customer-supplied UTR is not proof of payment. Support tickets alone do not mark an order paid.

After checking availability, the merchant can use **Transactions → Update booking**, or the website's authenticated backend can call:

```js
const payment = await client.getPayment(paymentId);
await client.updateBooking(paymentId, "CONFIRMED", payment.bookingVersion);
```

This calls `POST /api/v1/payments/{paymentId}/booking-status` with `bookingStatus` and `bookingVersion`. Allowed transitions are `PENDING → CONFIRMED|REJECTED|CANCELLED` and `CONFIRMED → CANCELLED`. Confirmation requires payment status `SUCCESS`; for COD, SUCCESS means the order was placed and cash is still due. Repeating the same state is idempotent. A stale version with a different state is rejected; fetch the current payment and show it to the operator before retrying. Rejection/cancellation never issues a refund or changes the payment result.

### Events delivered to your website

Existing terminal events (`SUCCESS`, `FAILED`, `EXPIRED`, `CANCELLED`) keep their existing format. Opting into workflow events adds:

| Event | Meaning |
| --- | --- |
| `ORDER_RECEIVED` | Payment first becomes active or queued; an abandoned INITIATED checkout does not alert the merchant. |
| `PAYMENT_VERIFYING` | Customer submitted payment for checking. |
| `PAYMENT_DELAYED` | Payment window ended without confirmation. Do not ask an already-debited customer to pay again. |
| `PAYMENT_CONFIRMED` | Payment succeeded, or a COD order was placed. |
| `PAYMENT_CANCELLED` | Checkout payment session cancelled. |
| `PAYMENT_REVIEW_REQUESTED` | A review ticket was created. |
| `PAYMENT_REVIEW_REQUIRED` | Late bank evidence needs merchant review. |
| `BOOKING_CONFIRMED`, `BOOKING_REJECTED`, `BOOKING_CANCELLED` | Merchant booking decision changed. |

Workflow payloads include `event`, a stable `eventId`, `paymentId`, `orderId`, `paymentStatus`, `bookingStatus`, `bookingVersion`, `amount`, `currency`, `method`, `occurredAt`, and `message`. These events use the same `X-Gateway-Signature` verification and `X-Gateway-Delivery-Id` headers as existing webhooks. Verify the **raw request body** with `client.verifyWebhook` before processing it.

Receiver workflow:

1. Verify signature, persist the event and delivery ID in your database with unique constraints, and acknowledge with HTTP 2xx. Handle duplicates successfully without repeating side effects.
2. Process the stored event asynchronously. Events can arrive out of order: retrieve the current payment with `getPayment(paymentId)` before updating payment status; use `bookingVersion` to prevent an older booking event overwriting a newer decision. Never treat every event as a payment success.
3. Update your order database and publish an update through your website's own WebSocket/SSE service, or let the customer page poll your authenticated order endpoint. Verify order ownership on that endpoint.
4. Let Paykr send WhatsApp if enabled. Do not also send the same WhatsApp notification from your webhook handler. If your website handles WhatsApp itself, turn off Paykr's customer WhatsApp switch.

WhatsApp messages are queued independently of webhook delivery; failure of one does not imply failure of the other or change payment status. The Notifications page shows `PENDING`, `SENT` (sender acknowledged; not a read receipt), `FAILED`, `SKIPPED` (no longer relevant/allowed), or `UNKNOWN` (delivery may have happened). Disconnected senders retry up to five attempts. UNKNOWN messages are not automatically resent; check delivery, wait at least five minutes, and explicitly retry if necessary. Outdated payment/booking alerts and revoked consent are checked before sending. Test-mode payments do not send these real notifications.

Deploy this feature with its database migration, then restart queue workers. Keep notification switches disabled until your sender and webhook receiver are ready. No historical notification backfill occurs.
