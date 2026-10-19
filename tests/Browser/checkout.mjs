import { chromium, expect } from "@playwright/test";
import { createServer } from "node:http";
import { createHmac } from "node:crypto";
import { execFile } from "node:child_process";
import { mkdir } from "node:fs/promises";
import { AndroidObserverDriver } from "../Fixtures/android/driver.mjs";
let input = "";
for await (const chunk of process.stdin) input += chunk;
const fixture = JSON.parse(input);
const base = "http://127.0.0.1:8022",
    shop = "http://127.0.0.1:8023",
    hooks = [];
const control = (payload) =>
    new Promise((resolve, reject) => {
        const child = execFile(
            fixture.php,
            ["tests/Support/checkout-control.php"],
            { env: process.env },
            (err, out, stderr) =>
                err
                    ? reject(new Error(stderr || out))
                    : resolve(out ? JSON.parse(out) : null),
        );
        child.stdin.end(JSON.stringify({ ...fixture, ...payload }));
    });
const server = createServer(async (req, res) => {
    if (req.url === "/webhook") {
        let body = "";
        for await (const part of req) body += part;
        hooks.push({
            body: JSON.parse(body),
            valid:
                req.headers["x-gateway-signature"] ===
                "sha256=" +
                    createHmac("sha256", "isolated-checkout-secret")
                        .update(body)
                        .digest("hex"),
        });
        res.writeHead(200);
        res.end("ok");
        return;
    }
    res.setHeader("Content-Type", "text/html");
    res.end(
        `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><h1>Test store</h1><button data-paykr-pay data-order-id="${new URL(req.url, shop).searchParams.get("order") || "BROWSER"}" data-amount="${Number(new URL(req.url, shop).searchParams.get("amount")) || 10}">Pay now</button><script>window.results=[];window.addEventListener('paykr:payment:result',e=>window.results.push(e.detail));</script><script src="${base}/paykr.js" data-paykr-key="${fixture.key}"></script></body></html>`,
    );
});
await new Promise((resolve) => server.listen(8023, "127.0.0.1", resolve));
const browser = await chromium.launch({
        headless: true,
        ...(process.env.PAYKR_BROWSER_PATH
            ? { executablePath: process.env.PAYKR_BROWSER_PATH }
            : {}),
    }),
    driver = new AndroidObserverDriver({ apiBaseUrl: base });
await mkdir("storage/app/private/evidence/phase9", { recursive: true });
let seq = 0;
async function open(context, label, amount = 10, customer = null) {
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    if (customer) {
        await page.route(`${base}/api/v1/payments`, async (route) => {
            if (route.request().method() !== "POST") return route.continue();
            await route.continue({
                postData: JSON.stringify({ ...route.request().postDataJSON(), customer }),
            });
        });
    }
    await page.goto(`${shop}/?order=${label}-${++seq}&amount=${amount}`);
    const created = page.waitForResponse(
        (r) =>
            r.url() === base + "/api/v1/payments" &&
            r.request().method() === "POST",
    );
    await page.getByRole("button", { name: "Pay now" }).click();
    const response = await created;
    const data = await response.json();
    expect(response.status(), JSON.stringify(data)).toBe(201);
    const frame = page.frameLocator("iframe");
    await expect(
        frame.getByRole("heading", { name: "Sign in to pay" }),
    ).toBeVisible();
    return { page, frame, id: data.paymentId, errors };
}
async function signIn(
    view,
    mobile,
    { existing = false, expectQr = true } = {},
) {
    const { page, frame } = view;
    await frame.getByLabel("WhatsApp mobile number").fill(mobile);
    const sent = page.waitForResponse((r) =>
        r.url().endsWith("/customer/send-otp"),
    );
    await frame.getByRole("button", { name: "Continue", exact: true }).click();
    if (!existing) {
        await expect(
            frame.getByRole("heading", { name: "Create your account" }),
        ).toBeVisible();
        await frame.getByLabel("Full name").fill("Checkout Tester");
        await frame.getByLabel("Email address").fill(`${mobile}@example.test`);
        await frame
            .getByRole("button", { name: "Continue", exact: true })
            .click();
    }
    const otp = await (await sent).json();
    expect(otp.devOtp).toMatch(/^\d{6}$/);
    await frame.getByLabel("Verification code").fill(otp.devOtp);
    await expect(frame.getByRole("dialog", { name: "Sign in" })).toHaveCount(0);
    if (expectQr)
        await expect(frame.getByAltText("UPI payment QR code")).toBeVisible();
}
async function sms(utr, extra = {}) {
    const amount = extra.amount || 10;
    const body = JSON.stringify({
        receivingAccountId: fixture.accountId,
        sender: "VM-HDFCBK",
        rawText: `Received Rs ${amount} via UPI UTR: ${utr}`,
        observedAt: new Date().toISOString(),
        ...extra,
    });
    const timestamp = String(Date.now());
    const r = await fetch(`${base}/api/v1/pollers/android/events`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Device-Id": driver.deviceId,
            "X-Timestamp": timestamp,
            "X-Signature": createHmac("sha256", driver.signingKey)
                .update(body + ":" + timestamp)
                .digest("hex"),
        },
        body,
    });
    return { status: r.status, body: await r.json() };
}

try {
    await driver.pair(fixture.enrollmentToken, fixture.merchantId);
    await driver.sendHeartbeat();
    for (const [label, viewport, mobile] of [
        ["desktop", { width: 1440, height: 900 }, "9000000001"],
        ["mobile", { width: 390, height: 844 }, "9000000002"],
    ]) {
        const context = await browser.newContext({ viewport }),
            view = await open(context, label, 10, label === "desktop" ? {
                name: "Merchant supplied customer", mobileNumber: mobile,
                email: `${mobile}@example.test`,
            } : null);
        expect((await control({ operation: "inspect", id: view.id })).status).toBe("INITIATED");
        await signIn(view, mobile, { existing: label === "desktop" });
        expect((await control({ operation: "inspect", id: view.id })).status).toBe("ACTIVE");
        await expect(
            view.frame.getByRole("button", {
                name: "Payment Done",
                exact: true,
            }),
        ).toHaveCount(0);
        await control({ operation: "deadline", id: view.id, seconds: 235 });
        await expect(
            view.frame.getByRole("button", {
                name: "Payment Done",
                exact: true,
            }),
        ).toBeVisible({ timeout: 12000 });
        await view.page.evaluate(() =>
            window.postMessage(
                { event: "paykr.payment_success", data: { orderId: "FORGED" } },
                "*",
            ),
        );
        expect(await view.page.evaluate(() => window.results)).toEqual([]);
        await view.page.screenshot({
            path: `storage/app/private/evidence/phase9/${label}-qr.png`,
            fullPage: true,
        });
        await view.frame
            .getByRole("button", { name: "Payment Done", exact: true })
            .click();
        await expect(
            view.frame.getByText("Checking UPI transaction in real-time…"),
        ).toBeVisible();
        const observed = await sms(`12345678900${seq}`);
        expect(observed.status, JSON.stringify(observed.body)).toBe(200);
        expect(observed.body.status, JSON.stringify(observed.body)).toBe(
            "VERIFIED",
        );
        await new Promise((r) => setTimeout(r, 5200));
        await control({ operation: "finalize", id: view.id });
        await expect
            .poll(
                () =>
                    view.page.evaluate(() =>
                        window.results.some((r) => r.status === "SUCCESS"),
                    ),
                { timeout: 15000 },
            )
            .toBe(true);
        expect(hooks.some((h) => h.valid)).toBe(true);
        expect(view.errors).toEqual([]);
        await context.close();
        console.log(
            `${label}: PASS — SDK → OTP → QR → processing → signed Android SMS → success + signed webhook`,
        );
    }
    const codContext = await browser.newContext(),
        cod = await open(codContext, "cod");
    await signIn(cod, "9000000003");
    await cod.frame
        .getByRole("button", { name: "Cash on Delivery", exact: true })
        .click();
    await cod.frame
        .getByRole("button", { name: "Place cash on delivery order" })
        .click();
    await expect
        .poll(() =>
            cod.page.evaluate(() =>
                window.results.some((r) => r.status === "SUCCESS"),
            ),
        )
        .toBe(true);
    await codContext.close();
    console.log("COD: PASS");
    await control({ operation: "mode", mode: "OFFLINE" });
    const counterContext = await browser.newContext(),
        counter = await open(counterContext, "counter");
    await signIn(counter, "9000000004");
    await counter.frame
        .getByRole("button", { name: "Cash at Till", exact: true })
        .click();
    await expect(
        counter.frame.getByText("Waiting for cashier confirmation"),
    ).toBeVisible();
    await control({ operation: "counter", id: counter.id });
    await expect
        .poll(
            () =>
                counter.page.evaluate(() =>
                    window.results.some((r) => r.status === "SUCCESS"),
                ),
            { timeout: 12000 },
        )
        .toBe(true);
    await counterContext.close();
    await control({ operation: "mode", mode: "ONLINE" });
    console.log("Counter: PASS");

    async function cancel(view) {
        await view.frame
            .getByRole("button", { name: "Close checkout", exact: true })
            .click();
        await view.frame
            .getByRole("button", { name: "Cancel payment", exact: true })
            .click();
    }
    const returnContext = await browser.newContext();
    const returnView = await open(returnContext, "cancel-return", 13.37);
    const returnUrl = `${shop}/?order=cancelled-return`;
    await control({ operation: "return-url", id: returnView.id, url: returnUrl });
    await returnView.page.goto(`${base}/checkout/${returnView.id}`);
    await cancel({ frame: returnView.page });
    await expect(returnView.page.getByRole("link", { name: "Return to merchant" })).toHaveAttribute("href", returnUrl);
    await expect(returnView.page).toHaveURL(returnUrl);
    expect((await control({ operation: "inspect", id: returnView.id })).status).toBe("CANCELLED");
    await returnContext.close();
    console.log("Standalone cancellation: PASS — returns to the merchant with the payment cancelled");
    const q1c = await browser.newContext(),
        q1 = await open(q1c, "queue-first");
    await signIn(q1, "9000000005");
    const q2c = await browser.newContext(),
        q2 = await open(q2c, "queue-second");
    await signIn(q2, "9000000006", { expectQr: false });
    await expect(q2.frame.getByText("You’re in the queue")).toBeVisible();
    await sms("123456789101");
    await new Promise((r) => setTimeout(r, 5200));
    await control({ operation: "finalize", id: q1.id });
    await expect(q2.frame.getByAltText("UPI payment QR code")).toBeVisible({
        timeout: 12000,
    });
    await cancel(q2);
    await q1c.close();
    await q2c.close();
    console.log("Queue: PASS — two customers, automatic promotion");
    const graceContext = await browser.newContext(),
        grace = await open(graceContext, "cancel-grace", 11);
    await signIn(grace, "9000000007");
    const graceObservation = await sms("123456789102", { amount: 11 });
    expect(graceObservation.body.status).toBe("VERIFIED");
    await cancel(grace);
    await new Promise((r) => setTimeout(r, 5200));
    await control({ operation: "finalize", id: grace.id });
    expect((await control({ operation: "inspect", id: grace.id })).status).toBe(
        "CANCELLED",
    );
    await graceContext.close();
    console.log(
        "Cancellation: PASS — remains cancelled after verification grace",
    );
    const lateContext = await browser.newContext(),
        late = await open(lateContext, "late");
    await signIn(late, "9000000008");
    const bankTimestamp = new Date().toISOString();
    await control({ operation: "deadline", id: late.id, seconds: 1 });
    await expect
        .poll(
            () =>
                late.page.evaluate(() =>
                    window.results.some((r) => r.status === "EXPIRED"),
                ),
            { timeout: 12000 },
        )
        .toBe(true);
    await sms("123456789103");
    await sms("123456789104", {
        paymentSessionId: late.id,
        observedAt: bankTimestamp,
    });
    const lateResult = await control({ operation: "inspect", id: late.id });
    expect(lateResult.status).toBe("EXPIRED");
    expect(lateResult.reviews).toBeGreaterThan(0);
    expect(lateResult.tickets).toBeGreaterThan(0);
    await lateContext.close();
    console.log(
        "Late credit: PASS — expired order stays expired and creates a review ticket",
    );
    const oldContext = await browser.newContext(),
        old = await open(oldContext, "duplicate");
    await signIn(old, "9000000009");
    const newContext = await browser.newContext(),
        newPage = await newContext.newPage();
    newPage.setDefaultTimeout(15000);
    await newPage.goto(`${base}/checkout/${old.id}`);
    await expect(
        newPage.getByRole("heading", {
            name: "Payment opened on another device",
        }),
    ).toBeVisible();
    await newPage.getByRole("button", { name: "Sign in to continue" }).click();
    await signIn({ page: newPage, frame: newPage }, "9000000009", {
        existing: true,
        expectQr: false,
    });
    await newPage
        .getByRole("button", { name: "End previous session and restart" })
        .click();
    await expect(newPage).not.toHaveURL(new RegExp(old.id));
    await expect(newPage.getByAltText("UPI payment QR code")).toBeVisible();
    await cancel({ frame: newPage });
    await expect(newPage.getByText("No return link was provided for this order.", { exact: false })).toBeVisible();
    await expect(newPage.getByRole("button", { name: "Try again", exact: true })).toHaveCount(0);
    await oldContext.close();
    await newContext.close();
    console.log(
        "Duplicate device: PASS — authenticated restart replaces the previous session",
    );
    const fraudContext = await browser.newContext(),
        fraud = await open(fraudContext, "fraud");
    await signIn(fraud, "9000000010");
    const fraudResult = await control({ operation: "fraud", id: fraud.id });
    expect(fraudResult.reason).toBe("FRAUDULENT_PAYMENT_INJECTION");
    await expect(
        fraud.frame.getByRole("heading", {
            name: "FRAUDULENT ACTIVITY DETECTED",
            exact: true,
        }),
    ).toBeVisible({ timeout: 12000 });
    await fraudContext.close();
    console.log(
        "Fraud: PASS — forged customer SMS triggers a ban and blocked checkout notice",
    );
} finally {
    await browser.close();
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
}
