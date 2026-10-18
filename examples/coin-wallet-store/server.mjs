import http from "node:http";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
import { DatabaseSync } from "node:sqlite";
import { pathToFileURL } from "node:url";
import { PaykrPaymentClient } from "../../packages/merchant-sdk/src/index.js";

const escape = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[c],
    );
const titles = {
    success: "Payment Successful!",
    failed: "Payment Unsuccessful",
    suspicious: "Payment Under Review",
    expired: "Payment Session Expired",
    cancelled: "Payment Cancelled",
};

export function createStore({
    client,
    database = ":memory:",
    publicUrl = "http://localhost:5000",
}) {
    const db = new DatabaseSync(database);
    db.exec(
        "CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, payment_id TEXT UNIQUE, amount_paise INTEGER NOT NULL, coins INTEGER NOT NULL, credited INTEGER NOT NULL DEFAULT 0)",
    );
    const balance = () => ({
        coins:
            50 +
            db
                .prepare(
                    "SELECT COALESCE(SUM(coins),0) AS coins FROM orders WHERE credited=1",
                )
                .get().coins,
        history: db
            .prepare(
                "SELECT id AS orderId, coins FROM orders WHERE credited=1 ORDER BY rowid DESC",
            )
            .all(),
    });
    const credit = (event) => {
        const order = db
            .prepare("SELECT * FROM orders WHERE id=?")
            .get(event.orderId);
        if (
            !order ||
            order.payment_id !== event.paymentId ||
            event.currency !== "INR" ||
            Number(event.amountPaise) !== order.amount_paise
        )
            throw new Error("Payment does not match the stored order.");
        if (event.event !== "SUCCESS" && event.status !== "SUCCESS")
            return { credited: false, ...balance() };
        const result = db
            .prepare("UPDATE orders SET credited=1 WHERE id=? AND credited=0")
            .run(order.id);
        return { credited: result.changes === 1, ...balance() };
    };
    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, publicUrl);
        const json = (status, body) => {
            res.writeHead(status, {
                "Content-Type": "application/json",
                "Cache-Control": "no-store",
            });
            res.end(JSON.stringify(body));
        };
        try {
            if (req.method === "GET" && url.pathname === "/store.js") {
                res.writeHead(200, {
                    "Content-Type": "application/javascript",
                });
                return res.end(
                    readFileSync(new URL("./store.js", import.meta.url)),
                );
            }
            if (
                req.method === "GET" &&
                ["/health", "/api/wallet-balance"].includes(url.pathname)
            )
                return json(200, { status: "UP", ...balance() });
            if (req.method === "POST") {
                // This is a local single-wallet demo. Browser mutations must come from this store.
                if (
                    url.pathname !== "/api/webhook" &&
                    req.headers.origin &&
                    req.headers.origin !== new URL(publicUrl).origin
                )
                    return json(403, { error: "Origin not allowed" });
                let raw = Buffer.alloc(0);
                for await (const chunk of req) {
                    raw = Buffer.concat([raw, chunk]);
                    if (raw.length > 65536)
                        return json(413, { error: "Request too large" });
                }
                if (url.pathname === "/api/webhook")
                    return json(
                        200,
                        credit(
                            client.verifyWebhook(
                                raw,
                                req.headers["x-gateway-signature"],
                            ),
                        ),
                    );
                const body = JSON.parse(raw.toString() || "{}");
                if (url.pathname === "/api/checkout") {
                    const amount = String(body.price ?? "");
                    if (
                        !/^\d+(\.\d{1,2})?$/.test(amount) ||
                        Number(amount) < 1 ||
                        Number(amount) > 10000
                    )
                        return json(400, {
                            error: "Minimum top-up amount is ₹1.00; maximum is ₹10,000.",
                        });
                    const paise = Math.round(Number(amount) * 100),
                        coins = Math.floor(paise / 100);
                    const orderId = "COIN-ORD-" + randomUUID();
                    db.prepare(
                        "INSERT INTO orders(id,amount_paise,coins) VALUES(?,?,?)",
                    ).run(orderId, paise, coins);
                    const payment = await client.createPayment({
                        orderId,
                        amount,
                        metadata: { coins, packName: "Custom Top-Up" },
                        returnUrl:
                            publicUrl + "/order-success?orderId=" + orderId,
                    });
                    db.prepare("UPDATE orders SET payment_id=? WHERE id=?").run(
                        payment.paymentId,
                        orderId,
                    );
                    return json(200, payment);
                }
                if (url.pathname === "/api/verify-payment") {
                    const order = db
                        .prepare("SELECT * FROM orders WHERE id=?")
                        .get(String(body.orderId || ""));
                    if (!order?.payment_id)
                        return json(404, { error: "Order not found" });
                    return json(200, {
                        success: true,
                        ...credit(await client.getPayment(order.payment_id)),
                    });
                }
                return json(404, { error: "Not found" });
            }
            if (req.method !== "GET")
                return json(405, { error: "Method not allowed" });
            const page = url.pathname.replace(/^\/(order-)?/, "");
            if (!["", "wallet", ...Object.keys(titles)].includes(page))
                return json(404, { error: "Not found" });
            const orderId =
                url.searchParams.get("orderId") ||
                url.searchParams.get("order_id") ||
                "";
            // Query strings and popup results never prove payment or change the wallet.
            const order = db
                .prepare("SELECT * FROM orders WHERE id=?")
                .get(orderId);
            const heading =
                page === "success" && !order?.credited
                    ? "Awaiting payment confirmation"
                    : titles[page] ||
                      (page === "wallet" ? "Customer Account" : "Paykr Store");
            res.writeHead(200, {
                "Content-Type": "text/html; charset=utf-8",
                "Cache-Control": "no-store",
                "Content-Security-Policy":
                    "default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            });
            res.end(
                `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>${heading}</title><style>body{font:18px system-ui;background:#f1f5f9;color:#172033;max-width:650px;margin:8vh auto;padding:24px}main{background:white;padding:32px;border-radius:20px}input,button{font:inherit;padding:12px;margin:8px 0}a{color:#2563eb}</style><main><h1>${heading}</h1><p>Self-Hosted UPI Payment Gateway · Local demo</p><p>Current Vault Balance: <strong>${balance().coins} coins</strong></p><p>${escape(orderId)}</p><p>Top-ups start at ₹1.00. One rupee buys one coin.</p><p>Credits appear after your payment is verified.</p><form id="topup"><label>Top-up amount (₹) <input name="price" type="number" min="1" max="10000" step="0.01" value="1" required></label><button>Pay securely</button></form><p id="status" role="status"></p><script src="/store.js" defer></script><a href="/">Try Again</a> · <a href="/wallet">Wallet</a><p>Contact Payment Support through the checkout help panel.</p></main></html>`,
            );
        } catch (error) {
            json(400, {
                error: error.message.startsWith("Paykr request")
                    ? "Gateway request failed"
                    : error.message,
            });
        }
    });
    server.on("close", () => db.close());
    return server;
}
if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    const port = Number(process.env.PORT || 5000);
    const server = createStore({
        client: new PaykrPaymentClient(),
        database: process.env.WALLET_DATABASE || "wallet.sqlite",
        publicUrl: process.env.STORE_URL || `http://localhost:${port}`,
    });
    server.listen(port, "127.0.0.1", () =>
        console.log(`Local demo: http://localhost:${port}`),
    );
}
