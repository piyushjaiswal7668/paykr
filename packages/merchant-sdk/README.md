# Paykr merchant SDK 2.0.0

Node 22+; no runtime dependencies. This version keeps `PaykrPaymentClient.createPayment`,
`getPayment`, `verifyWebhook` and browser `Paykr` / `PaykrCheckout` exports. Authentication
now requires a merchant **secret key**, replacing the insecure merchant-ID-only header.
`customer.fullName` remains accepted as an alias for `name`; idempotencyKey is sent as a header.
Legacy autoActivate has no effect: customers authenticate through checkout before activation.

```js
import { PaykrPaymentClient } from '@paykr/merchant-sdk';
const paykr = new PaykrPaymentClient({
  apiUrl: process.env.PAYKR_API_URL,
  secretKey: process.env.PAYKR_SECRET_KEY,
  webhookSecret: process.env.PAYKR_WEBHOOK_SECRET,
});
const payment = await paykr.createPayment({orderId:'ORDER-123', amount:'10.00', idempotencyKey:'ORDER-123'});
// Send payment.checkoutUrl to the customer.
const state = await paykr.getPayment(payment.paymentId);
const event = paykr.verifyWebhook(rawRequestBody, requestHeaders['x-gateway-signature']);
```

Verify the untouched body before JSON parsing; never reserialize it for verification.
Match orderId, paymentId, currency and amount against your stored order, and grant goods
only for SUCCESS, once per order. A valid signature authenticates a delivery, but does not
prevent duplicate deliveries. Browser callbacks only update the UI. Secret keys belong on
servers; use the separately hosted `/paykr.js` with a publishable key in browsers.

Build from the repository root before packing: `npm run build`, then
`npm pack ./packages/merchant-sdk`. The ESM browser export is generated from the same
reviewed source as the hosted SDK. The tarball is ready for private installation/release;
no package registry publication is performed automatically.

For delayed payment confirmation, call `await paykr.recheckPayment(paymentId)` on
your server. If `canRequestReview` is true, an explicit customer review request can
call `await paykr.requestPaymentReview(paymentId, { reference, note })`. Both use the
original payment ID. `verificationStatus: "AWAITING_EVIDENCE"` is not payment success;
a customer-provided reference is an unverified claim. See
[the delayed confirmation API guide](../../docs/INTEGRATION.md#delayed-confirmation-recheck-and-request-review).

Booking updates: `client.updateBooking(paymentId, "CONFIRMED", bookingVersion)`
(the current version comes from `getPayment`). Booking state is separate from
payment and never issues refunds. Other decisions are `REJECTED` and `CANCELLED`.

Customer WhatsApp consent: pass `whatsappUpdatesConsent: true` to `createPayment`
only after explicit customer consent, or call
`client.setWhatsappUpdatesConsent(paymentId, enabled)` from your server.
Enable merchant notification preferences in the Paykr dashboard. See
[notification setup and signed website events](../../docs/INTEGRATION.md#whatsapp-notifications-and-website-booking-updates).
