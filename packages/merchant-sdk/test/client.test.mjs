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
