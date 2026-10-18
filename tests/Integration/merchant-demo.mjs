import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { createStore } from "../../examples/coin-wallet-store/server.mjs";
import { PaykrPaymentClient } from "../../packages/merchant-sdk/src/index.js";
let input = "";
for await (const chunk of process.stdin) input += chunk;
const fixture = JSON.parse(input);
const secret = "isolated-checkout-secret";
const client = new PaykrPaymentClient({
    apiUrl: "http://127.0.0.1:8022",
    secretKey: fixture.key,
    webhookSecret: secret,
});
const server = createStore({ client, publicUrl: "http://127.0.0.1:8023" });
await new Promise((resolve) => server.listen(8023, "127.0.0.1", resolve));
const request = async (path, body, headers = {}) => {
    const response = await fetch(
        "http://127.0.0.1:8023" + path,
        body === undefined
            ? {}
            : {
                  method: "POST",
                  headers: { "Content-Type": "application/json", ...headers },
                  body: typeof body === "string" ? body : JSON.stringify(body),
              },
    );
    return { status: response.status, body: await response.text() };
};
try {
    assert.equal(JSON.parse((await request("/health")).body).coins, 50);
    assert.match((await request("/")).body, /Self-Hosted UPI/);
    assert.equal((await request("/api/checkout", { price: 0.5 })).status, 400);
    for (const price of [1, 250]) {
        const result = await request("/api/checkout", { price, coins: 999999 });
        assert.equal(result.status, 200, result.body);
        const payment = JSON.parse(result.body);
        assert.match(payment.checkoutUrl, /\/checkout\//);
        assert.equal(
            (await client.getPayment(payment.paymentId)).amountPaise,
            price * 100,
        );
        const before = JSON.parse(
            (await request("/api/wallet-balance")).body,
        ).coins;
        const verify = JSON.parse(
            (
                await request("/api/verify-payment", {
                    orderId: payment.orderId,
                    coins: 999999,
                })
            ).body,
        );
        assert.equal(verify.credited, false);
        assert.equal(verify.coins, before);
        const event = {
            event: "SUCCESS",
            paymentId: payment.paymentId,
            orderId: payment.orderId,
            amountPaise: price * 100,
            currency: "INR",
        };
        const raw = JSON.stringify(event),
            signature =
                "sha256=" +
                createHmac("sha256", secret).update(raw).digest("hex");
        assert.equal(
            (
                await request("/api/webhook", raw, {
                    "X-Gateway-Signature": "sha256=bad",
                })
            ).status,
            400,
        );
        assert.equal(
            JSON.parse(
                (
                    await request("/api/webhook", raw, {
                        "X-Gateway-Signature": signature,
                    })
                ).body,
            ).credited,
            true,
        );
        assert.equal(
            JSON.parse(
                (
                    await request("/api/webhook", raw, {
                        "X-Gateway-Signature": signature,
                    })
                ).body,
            ).credited,
            false,
        );
        assert.equal(
            JSON.parse((await request("/api/wallet-balance")).body).coins,
            before + price,
        );
        assert.throws(() => client.verifyWebhook(raw + " ", signature));
        assert.throws(() => client.verifyWebhook(raw, signature, "wrong"));
        assert.throws(() =>
            client.verifyWebhook(raw, "sha256=" + "0".repeat(64)),
        );
    }
    assert.equal(
        (
            await request("/api/verify-payment", {
                orderId: "forged",
                coins: 99999,
            })
        ).status,
        404,
    );
    for (const state of [
        "success",
        "failed",
        "suspicious",
        "expired",
        "cancelled",
    ]) {
        const response = await request(
            "/order-" + state + "?orderId=%3Cscript%3Ebad%3C%2Fscript%3E",
        );
        assert.equal(response.status, 200);
        assert.ok(!response.body.includes("<script>bad</script>"));
    }
    const payload = {
        orderId: "SDK-IDEMPOTENT",
        amount: 1,
        customer: { phoneNumber: "9876543210", fullName: "Demo Customer" },
        idempotencyKey: "phase11-sdk-idempotency",
    };
    const first = await client.createPayment(payload),
        second = await client.createPayment(payload);
    assert.equal(first.paymentId, second.paymentId);
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.PAYKR_BROWSER_PATH
            ? { executablePath: process.env.PAYKR_BROWSER_PATH }
            : {}),
    });
    try {
        const page = await browser.newPage();
        await page.goto("http://127.0.0.1:8023");
        await page.getByRole("button", { name: "Pay securely" }).click();
        await page.waitForURL("**/checkout/**");
        await page
            .getByText("Sign in", { exact: false })
            .first()
            .waitFor({ timeout: 15000 });
    } finally {
        await browser.close();
    }
    console.log(
        "PASS: real Laravel create/query/idempotency; demo top-up, signed webhook, replay/tamper rejection, server-side pending verification, escaped outcome pages.",
    );
} finally {
    await new Promise((resolve) => server.close(resolve));
}
