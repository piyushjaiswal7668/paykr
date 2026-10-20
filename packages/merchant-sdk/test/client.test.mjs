import { test } from "node:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { PaykrPaymentClient, Paykr, PaykrCheckout } from "../src/index.js";
test("preserves exports and verifies only exact raw signed bodies", () => {
    assert.equal(Paykr, PaykrCheckout);
    const client = new PaykrPaymentClient({
        secretKey: "sk_test_" + "a".repeat(64),
        webhookSecret: "fixture-secret",
    });
    const body = '{"event":"SUCCESS","metadata":{"name":"पियूष"}}';
    const signature =
        "sha256=" +
        createHmac("sha256", "fixture-secret").update(body).digest("hex");
    assert.equal(client.verifyWebhook(body, signature).event, "SUCCESS");
    for (const value of [
        "",
        "sha256=x",
        "sha256=" + "0".repeat(64),
        signature + "0",
    ])
        assert.throws(() => client.verifyWebhook(body, value));
    assert.throws(() => client.verifyWebhook(body + " ", signature));
    assert.throws(() => client.verifyWebhook(body, signature, "wrong"));
    assert.throws(() => new PaykrPaymentClient({ merchantId: "legacy-id" }));
});

test("rechecks the existing payment and requests review using the secret key", async (t) => {
    const calls = [];
    t.mock.method(globalThis, "fetch", async (url, options) => {
        calls.push({ url, options });
        return { ok: true, json: async () => ({ verificationStatus: calls.length === 1 ? "AWAITING_EVIDENCE" : "REVIEW_REQUESTED" }) };
    });
    const key = "sk_test_" + "a".repeat(64);
    const client = new PaykrPaymentClient({ apiUrl: "https://paykr.example", secretKey: key });
    assert.equal((await client.recheckPayment("payment/123")).verificationStatus, "AWAITING_EVIDENCE");
    assert.equal((await client.requestPaymentReview("payment/123", { reference: "123456789012" })).verificationStatus, "REVIEW_REQUESTED");
    assert.equal(calls[0].url, "https://paykr.example/api/v1/payments/payment%2F123/recheck");
    assert.equal(calls[1].url, "https://paykr.example/api/v1/payments/payment%2F123/request-review");
    assert.equal(calls[0].options.method, "POST");
    assert.equal(calls[1].options.headers["X-Api-Key"], key);
    assert.deepEqual(JSON.parse(calls[1].options.body), { reference: "123456789012" });
});

test("sends booking versions and explicit WhatsApp consent from the backend", async (t) => {
    const calls = [];
    t.mock.method(globalThis, "fetch", async (url, options) => {
        calls.push({ url, options });
        return { ok: true, json: async () => ({}) };
    });
    const client = new PaykrPaymentClient({ apiUrl: "https://paykr.example", secretKey: "sk_live_" + "a".repeat(64) });
    await client.updateBooking("payment/123", "CONFIRMED", 2);
    await client.setWhatsappUpdatesConsent("payment/123", false);
    assert.equal(calls[0].url, "https://paykr.example/api/v1/payments/payment%2F123/booking-status");
    assert.deepEqual(JSON.parse(calls[0].options.body), { bookingStatus: "CONFIRMED", bookingVersion: 2 });
    assert.equal(calls[1].url, "https://paykr.example/api/v1/payments/payment%2F123/notification-preferences");
    assert.deepEqual(JSON.parse(calls[1].options.body), { enabled: false });
});
