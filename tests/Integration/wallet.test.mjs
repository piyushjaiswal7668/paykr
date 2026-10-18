import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createStore } from "../../examples/coin-wallet-store/server.mjs";
test("server verification credits once, checks amount and persists across restart", async () => {
    const dir = mkdtempSync(join(tmpdir(), "paykr-wallet-"));
    let payment;
    const client = {
        createPayment: async (p) =>
            (payment = {
                ...p,
                paymentId: "fixture-payment",
                amountPaise: 100,
                currency: "INR",
                status: "INITIATED",
                checkoutUrl: "http://localhost/checkout/fixture",
            }),
        getPayment: async () => payment,
    };
    const options = { client, database: join(dir, "wallet.sqlite") };
    let server = createStore(options);
    const start = async () => {
        await new Promise((r) => server.listen(0, "127.0.0.1", r));
        return "http://127.0.0.1:" + server.address().port;
    };
    let base = await start();
    const post = async (path, body) => {
        const response = await fetch(base + path, {
            method: "POST",
            body: JSON.stringify(body),
        });
        return { status: response.status, body: await response.json() };
    };
    try {
        const order = (await post("/api/checkout", { price: 1, coins: 9999 }))
            .body;
        payment.status = "SUCCESS";
        payment.amountPaise = 200;
        assert.equal(
            (await post("/api/verify-payment", { orderId: order.orderId }))
                .status,
            400,
        );
        payment.amountPaise = 100;
        assert.equal(
            (await post("/api/verify-payment", { orderId: order.orderId })).body
                .coins,
            51,
        );
        assert.equal(
            (await post("/api/verify-payment", { orderId: order.orderId })).body
                .credited,
            false,
        );
        await new Promise((r) => server.close(r));
        server = createStore(options);
        base = await start();
        assert.equal(
            (await (await fetch(base + "/api/wallet-balance")).json()).coins,
            51,
        );
    } finally {
        await new Promise((r) => server.close(r));
        rmSync(dir, { recursive: true, force: true });
    }
});
