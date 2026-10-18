import { createHmac, timingSafeEqual } from "node:crypto";

export class PaykrPaymentClient {
    constructor(options = {}) {
        this.apiUrl = (
            options.apiUrl ||
            process.env.PAYKR_API_URL ||
            process.env.BASE_PAYMENT_API_URL ||
            "http://localhost:8012"
        ).replace(/\/+$/, "");
        this.secretKey =
            options.secretKey || options.apiKey || process.env.PAYKR_SECRET_KEY;
        this.webhookSecret =
            options.webhookSecret || process.env.PAYKR_WEBHOOK_SECRET;
        if (!/^sk_(live|test)_[a-zA-Z0-9]{32,}$/.test(this.secretKey || ""))
            throw new Error("A merchant secret API key is required.");
        const url = new URL(this.apiUrl);
        if (
            !["http:", "https:"].includes(url.protocol) ||
            url.username ||
            url.password
        )
            throw new Error("Invalid API URL.");
    }
    async request(path, options = {}) {
        const response = await fetch(this.apiUrl + path, {
            ...options,
            redirect: "error",
            signal: AbortSignal.timeout(15000),
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Api-Key": this.secretKey,
                ...options.headers,
            },
        });
        const body = await response.json();
        if (!response.ok) {
            const error = new Error(
                `Paykr request failed [HTTP ${response.status}]: ${body.code || "REQUEST_FAILED"}`,
            );
            error.status = response.status;
            error.code = body.code;
            throw error;
        }
        return body;
    }
    async createPayment(params) {
        const { idempotencyKey, customer, ...fields } = params;
        const normalized = customer
            ? { ...customer, name: customer.name ?? customer.fullName }
            : undefined;
        if (normalized) delete normalized.fullName;
        return this.request("/api/v1/payments", {
            method: "POST",
            headers: idempotencyKey
                ? { "Idempotency-Key": idempotencyKey }
                : {},
            body: JSON.stringify({
                ...fields,
                currency: fields.currency || "INR",
                customer: normalized,
            }),
        });
    }
    async getPayment(paymentId) {
        return this.request(
            "/api/v1/payments/" + encodeURIComponent(paymentId),
        );
    }
    verifyWebhook(rawBody, signature, customSecret = null) {
        const secret = customSecret || this.webhookSecret;
        if (!secret) throw new Error("Webhook secret is not configured.");
        if (
            typeof signature !== "string" ||
            !/^sha256=[a-fA-F0-9]{64}$/.test(signature)
        )
            throw new Error("Invalid webhook signature.");
        const expected = createHmac("sha256", secret).update(rawBody).digest();
        if (!timingSafeEqual(expected, Buffer.from(signature.slice(7), "hex")))
            throw new Error("Invalid webhook signature.");
        return JSON.parse(
            Buffer.isBuffer(rawBody) ? rawBody.toString("utf8") : rawBody,
        );
    }
}

export { Paykr, PaykrCheckout } from "./paykr-checkout.js";
