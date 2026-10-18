import { chromium, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
let input = "";
for await (const chunk of process.stdin) input += chunk;
const fixture = JSON.parse(input);
const browser = await chromium.launch({
    headless: true,
    ...(process.env.PAYKR_BROWSER_PATH
        ? { executablePath: process.env.PAYKR_BROWSER_PATH }
        : {}),
});
try {
    for (const mode of ["live", "blocked", "reconnect"]) {
        const context = await browser.newContext();
        const paymentId = fixture.payments[mode];
        const page = await context.newPage();
        const requests = [],
            events = [],
            errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        page.on("request", (request) => {
            if (request.url().endsWith("/state")) requests.push(Date.now());
        });
        page.on("websocket", (socket) =>
            socket.on("framereceived", ({ payload }) =>
                events.push(String(payload)),
            ),
        );
        if (mode === "blocked")
            await page.routeWebSocket(/.*/, (socket) => socket.close());
        await page.goto(`${fixture.url}/checkout/${paymentId}`);
        await expect(page.locator("[data-payment-status]")).toHaveAttribute(
            "data-payment-status",
            "ACTIVE",
        );
        if (mode !== "blocked")
            await expect(
                page.locator("[data-connection-status]"),
            ).toContainText("Live payment updates", { timeout: 15000 });
        if (mode === "blocked") {
            await expect
                .poll(() => requests.length, { timeout: 12000 })
                .toBeGreaterThanOrEqual(3);
            const gap = requests.at(-1) - requests.at(-2);
            expect(gap).toBeGreaterThan(4000);
            expect(gap).toBeLessThan(6500);
        }
        if (mode === "reconnect") await context.setOffline(true);
        const before = Date.now();
        execFileSync(
            fixture.php,
            ["tests/Support/realtime-transition.php", paymentId],
            { env: process.env },
        );
        if (mode === "reconnect") await context.setOffline(false);
        await expect(page.locator("[data-payment-status]")).toHaveAttribute(
            "data-payment-status",
            "CANCELLED",
            { timeout: 20000 },
        );
        if (mode === "live") {
            expect(
                events.some((event) => event.includes("PaymentStatusChanged")),
            ).toBe(true);
            expect(Date.now() - before).toBeLessThan(5000);
        }
        expect(errors).toEqual([]);
        console.log(
            `${mode}: PASS — authenticated state refreshed after committed transition`,
        );
        await context.close();
    }
} finally {
    await browser.close();
}
