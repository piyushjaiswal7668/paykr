export interface Customer {
    id: string;
    name: string;
    email: string;
    mobileNumber: string;
}
export interface Branding {
    appName: string;
    primaryColor: string;
    headerTheme: string;
    logoUrl?: string;
    sidebarNotice?: string;
    subbannerText?: string;
    trustBadgeText?: string;
    checkoutNotice?: string;
    footerGuarantee?: string;
    counterInstructions?: string;
    cardCounterInstructions?: string;
    termsUrl?: string;
    privacyPolicyUrl?: string;
    supportEmails?: string[];
    supportMobiles?: string[];
    hideTrustBadge?: boolean;
    hideSubbanner?: boolean;
    hidePoweredBy?: boolean;
    hideTimeSavingBanner?: boolean;
    enabledPaymentMethods?: string[];
}
export interface State {
    id: string;
    paymentId: string;
    orderId: string;
    amount: number;
    currency: string;
    merchantName: string;
    branding: Branding;
    status?: string;
    method?: string;
    customer?: Customer;
    upiUri?: string;
    expiresAt?: string;
    serverNow?: string;
    timeRemainingSeconds?: number;
    queuePosition?: number;
    returnUrl?: string;
    utr?: string;
    counterInstructions?: string;
    isTerminal?: boolean;
    hasActiveSessionConflict?: boolean;
    conflictingSession?: { checkoutUrl: string };
    enabledMethods?: string[];
    upiServerIndicator?: {
        label: string;
        warning?: string;
        isWorking: boolean;
    };
    blockedNotice?: { title: string; subtitle: string; message: string };
    newSessionId?: string;
    checkoutUrl?: string;
    error?: string;
    message?: string;
    code?: string;
    token?: string;
    exists?: boolean;
    devOtp?: string;
    deviceToken?: string;
    deviceId?: string;
}
export class ApiError extends Error {
    constructor(
        public data: Partial<State>,
        public status: number,
    ) {
        super(data.message || data.error || `Request failed (${status})`);
    }
}
export async function request<T = State>(
    path: string,
    body?: unknown,
    headers: Record<string, string> = {},
): Promise<T> {
    const response = await fetch(path, {
        method: body === undefined ? "GET" : "POST",
        headers: {
            Accept: "application/json",
            ...(body === undefined
                ? {}
                : { "Content-Type": "application/json" }),
            ...headers,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: "same-origin",
        cache: "no-store",
    });
    const data = await response.json();
    if (!response.ok) throw new ApiError(data, response.status);
    return data;
}
export function read<T>(key: string, fallback: T): T {
    try {
        return JSON.parse(localStorage.getItem(key) || "null") ?? fallback;
    } catch {
        return fallback;
    }
}
export function write(key: string, value: unknown) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        /* Checkout remains available without remembering accounts. */
    }
}
export function safeUrl(value?: string) {
    try {
        const url = new URL(value || "");
        return ["http:", "https:"].includes(url.protocol)
            ? url.href
            : undefined;
    } catch {
        return undefined;
    }
}
export interface Identity {
    deviceId: string;
    deviceToken: string;
}
async function keyPair(): Promise<CryptoKeyPair> {
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
        const r = indexedDB.open("paykr-device", 1);
        r.onupgradeneeded = () => r.result.createObjectStore("keys");
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    });
    const saved = await new Promise<CryptoKeyPair | undefined>(
        (resolve, reject) => {
            const r = db
                .transaction("keys")
                .objectStore("keys")
                .get("identity");
            r.onsuccess = () => resolve(r.result);
            r.onerror = () => reject(r.error);
        },
    );
    if (saved) {
        db.close();
        return saved;
    }
    const pair = await crypto.subtle.generateKey(
        { name: "ECDSA", namedCurve: "P-256" },
        false,
        ["sign", "verify"],
    );
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction("keys", "readwrite");
        tx.objectStore("keys").put(pair, "identity");
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
    db.close();
    return pair;
}
async function register(): Promise<Identity> {
    if (!crypto.subtle)
        throw new Error(
            "Secure checkout requires HTTPS. Please open the secure merchant payment link.",
        );
    const pair = await keyPair(),
        saved = read<Identity | null>("paykr.device.identity", null);
    if (saved) {
        const payload = JSON.stringify({
            purpose: "checkout",
            time: Date.now(),
        });
        const sig = await crypto.subtle.sign(
            { name: "ECDSA", hash: "SHA-256" },
            pair.privateKey,
            new TextEncoder().encode(payload),
        );
        const result = await request<{
            tokenValid: boolean;
            signatureValid: boolean;
        }>("/api/v1/devices/verify", {
            ...saved,
            payload,
            signature: btoa(String.fromCharCode(...new Uint8Array(sig))),
        });
        if (result.tokenValid && result.signatureValid) return saved;
        throw new Error(
            "This device is no longer authorized. Please contact support.",
        );
    }
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    ctx?.fillText("Paykr device identity", 2, 12);
    const device = await request<Identity>("/api/v1/devices/register", {
        publicKey: await crypto.subtle.exportKey("jwk", pair.publicKey),
        signals: {
            userAgent: navigator.userAgent,
            language: navigator.language,
            platform: navigator.platform,
            hardwareConcurrency: navigator.hardwareConcurrency,
            screenWidth: screen.width,
            screenHeight: screen.height,
            screenColorDepth: screen.colorDepth,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            canvasSignature: canvas.toDataURL(),
        },
    });
    if (!device.deviceToken)
        throw new Error(
            "Device authorization could not be restored. Please contact support.",
        );
    write("paykr.device.identity", device);
    return device;
}
export const deviceIdentity = () =>
    navigator.locks
        ? navigator.locks.request("paykr-device-registration", register)
        : register();
export interface Account {
    customer: Customer;
    token: string;
}
export function rememberedAccounts(): Account[] {
    const stored = read<unknown>("paykr.customer.accounts", []);
    const valid = (value: unknown): value is Account =>
        !!value &&
        typeof value === "object" &&
        "customer" in value &&
        !!value.customer &&
        typeof value.customer === "object" &&
        "id" in value.customer &&
        typeof value.customer.id === "string" &&
        "token" in value &&
        typeof value.token === "string";
    const accounts = Array.isArray(stored) ? stored.filter(valid) : [];
    const legacy = read<(Account & { expiresAt?: number }) | null>(
        "paykr.customer.identity",
        null,
    );
    if (
        valid(legacy) &&
        (!legacy.expiresAt || legacy.expiresAt > Date.now()) &&
        !accounts.some((a) => a.customer.id === legacy.customer.id)
    )
        accounts.push(legacy);
    return accounts;
}
export function customerToken() {
    try {
        return localStorage.getItem("ypg_customer_token") || "";
    } catch {
        return "";
    }
}
export function remember(account: Account) {
    const accounts = rememberedAccounts().filter(
        (a) => a.customer.id !== account.customer.id,
    );
    write("paykr.customer.accounts", [account, ...accounts].slice(0, 5));
    write("paykr.customer.identity", {
        ...account,
        savedAt: Date.now(),
        expiresAt: Date.now() + 7 * 86400000,
    });
    try {
        localStorage.setItem("paykr.customer.activeId", account.customer.id);
        localStorage.setItem("ypg_customer_token", account.token);
    } catch {
        /* This sign-in remains valid for the current checkout. */
    }
}
