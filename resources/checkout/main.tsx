import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
    Account,
    ApiError,
    Branding,
    Customer,
    Identity,
    State,
    deviceIdentity,
    rememberedAccounts,
    customerToken,
    remember,
    request,
    safeUrl,
} from "./api";
import { subscribeToUpdates } from "../js/realtime";
import "./style.css";
const bootstrap = JSON.parse(
    document.getElementById("checkout-bootstrap")!.textContent!,
) as { state: State; parentOrigin: string | null };
const config = JSON.parse(
    document.getElementById("paykr-realtime-config")!.textContent!,
);
const embedded = window.parent !== window;
const terminal = (s?: string) =>
    ["SUCCESS", "FAILED", "EXPIRED", "CANCELLED"].includes(s || "");
const labels: Record<string, string> = {
    UPI: "UPI QR",
    COD: "Cash on Delivery",
    CASH_COUNTER: "Cash at Till",
    CARD_COUNTER: "Card at Till",
};
function post(event: string, data: unknown = {}) {
    if (embedded && bootstrap.parentOrigin)
        window.parent.postMessage({ event, data }, bootstrap.parentOrigin);
}
function App() {
    const [state, setState] = useState<State>(bootstrap.state),
        [ready, setReady] = useState(false),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [duplicate, setDuplicate] = useState(false),
        [sheet, setSheet] = useState(""),
        [qr, setQr] = useState(""),
        [now, setNow] = useState(Date.now()),
        [connection, setConnection] = useState("polling");
    const [token, setToken] = useState(customerToken),
        [identity, setIdentity] = useState<Identity | null>(null);
    const [step, setStep] = useState("mobile"),
        [mobile, setMobile] = useState(""),
        [name, setName] = useState(""),
        [email, setEmail] = useState(""),
        [otp, setOtp] = useState(""),
        [resendAt, setResendAt] = useState(0);
    const offset = useRef(0),
        emitted = useRef(""),
        busyRef = useRef(false),
        current = useRef(state),
        tokenRef = useRef(token);
    current.current = state;
    tokenRef.current = token;
    const display = new URLSearchParams(location.search);
    const customName = display.get("customName")?.slice(0, 100),
        customAmount = display.get("customAmount");
    const b: Branding = state.branding || bootstrap.state.branding,
        id = bootstrap.state.id;
    const headers = () => ({
        ...(identity
            ? {
                  "X-Device-Id": identity.deviceId,
                  "X-Device-Token": identity.deviceToken,
              }
            : {}),
        ...(tokenRef.current
            ? { Authorization: `Bearer ${tokenRef.current}` }
            : {}),
    });
    const accept = (next: State) => {
        if (
            next.deviceId &&
            identity &&
            next.deviceId !== identity.deviceId &&
            !terminal(next.status)
        )
            setDuplicate(true);
        if (next.serverNow)
            offset.current = Date.parse(next.serverNow) - Date.now();
        setState((old) => ({ ...old, ...next }));
    };
    const fail = (e: unknown) => {
        if (
            e instanceof ApiError &&
            ["DEVICE_SESSION_MISMATCH", "DUPLICATE_SESSION_DETECTED"].includes(
                e.data.code || e.data.error || "",
            )
        ) {
            setDuplicate(true);
        } else if (
            e instanceof ApiError &&
            ["CUSTOMER_BANNED", "CUSTOMER_BLOCKED_BY_MERCHANT"].includes(
                e.data.code || "",
            )
        ) {
            setState((s) => ({ ...s, status: "BLOCKED" }));
        }
        setError(
            e instanceof Error
                ? e.message
                : "Unable to complete the request. Please try again.",
        );
    };
    const run = async (fn: () => Promise<void>) => {
        if (busyRef.current) return;
        busyRef.current = true;
        setBusy(true);
        setError("");
        try {
            await fn();
        } catch (e) {
            fail(e);
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    };
    const refresh = async () => {
        try {
            accept(
                await request<State>(
                    `/api/v1/checkout/${id}/state`,
                    undefined,
                    headers(),
                ),
            );
        } catch (e) {
            fail(e);
        } finally {
            setReady(true);
        }
    };
    useEffect(() => {
        let active = true;
        deviceIdentity()
            .then((d) => {
                if (active) setIdentity(d);
            })
            .catch((e) => {
                if (active) {
                    fail(e);
                    setReady(true);
                }
            });
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => {
            active = false;
            clearInterval(timer);
        };
    }, []);
    useEffect(() => {
        if (!identity) return;
        return subscribeToUpdates({
            channelName: `private-payment.${id}`,
            headers: headers(),
            config,
            refresh,
            onConnection: setConnection,
        });
    }, [identity, token]);
    useEffect(() => {
        if (!state.upiUri || !identity) {
            setQr("");
            return;
        }
        let disposed = false,
            url = "";
        fetch(`/checkout/${id}/qr.svg`, {
            headers: headers(),
            cache: "no-store",
        })
            .then(async (r) => {
                if (!r.ok) throw new Error("QR code could not be loaded.");
                url = URL.createObjectURL(await r.blob());
                if (!disposed) setQr(url);
                else URL.revokeObjectURL(url);
            })
            .catch(fail);
        return () => {
            disposed = true;
            if (url) URL.revokeObjectURL(url);
        };
    }, [state.upiUri, identity]);
    useEffect(() => {
        post("paykr.modal_ready");
        const handler = (e: MessageEvent) => {
            if (
                e.source === window.parent &&
                e.origin === bootstrap.parentOrigin &&
                e.data?.event === "paykr.request_cancel"
            )
                setSheet("cancel");
        };
        window.addEventListener("message", handler);
        return () => window.removeEventListener("message", handler);
    }, []);
    useEffect(() => {
        if (!state.status || emitted.current === state.status) return;
        let event = "";
        if (state.status === "SUCCESS") event = "paykr.payment_success";
        else if (state.status === "BLOCKED") event = "paykr.payment_blocked";
        else if (state.status === "EXPIRED") event = "paykr.payment_expired";
        else if (state.status === "FAILED") event = "paykr.payment_failed";
        else if (state.status === "CANCELLED")
            event = "paykr.payment_cancelled";
        if (!event) return;
        emitted.current = state.status;
        post(event, state);
        if (
            state.status === "SUCCESS" &&
            !embedded &&
            safeUrl(state.returnUrl)
        ) {
            const timer = setTimeout(
                () => location.assign(safeUrl(state.returnUrl)!),
                2000,
            );
            return () => clearTimeout(timer);
        }
    }, [state.status]);
    const action = (operation: string, body: unknown = {}) =>
        run(async () => {
            const next = await request<State>(
                `/api/v1/checkout/${id}/${operation}`,
                body,
                headers(),
            );
            if (
                (next.newSessionId || next.id) &&
                (next.newSessionId || next.id) !== id
            ) {
                location.assign(
                    `/checkout/${next.newSessionId || next.id}${embedded ? "?embedded=1" : ""}`,
                );
                return;
            }
            accept(next);
            setSheet("");
            if (bootstrap.parentOrigin && embedded)
                window.parent.postMessage(
                    {
                        type: "PAYKR_PAYMENT_HOOK",
                        action: operation.replaceAll("-", "_").toUpperCase(),
                        paymentId: id,
                        orderId: state.orderId,
                        details: {},
                    },
                    bootstrap.parentOrigin,
                );
        });
    const bindAccount = async (account: Account) => {
        remember(account);
        tokenRef.current = account.token;
        setToken(account.token);
        if (!duplicate)
            accept(
                await request<State>(
                    `/api/v1/checkout/${id}/bind-customer`,
                    {},
                    headers(),
                ),
            );
        setSheet("");
        setOtp("");
    };
    const useAccount = (account: Account) =>
        run(async () => {
            const verified = await request<State>(
                "/api/v1/customer/switch-account",
                { token: account.token },
            );
            if (!verified.customer) throw new Error("Please sign in again.");
            await bindAccount({
                customer: verified.customer,
                token: verified.token || account.token,
            });
        });
    const sendOtp = async (isNewUser: boolean) => {
        await request("/api/v1/customer/send-otp", {
            mobileNumber: mobile,
            name,
            email,
            isNewUser,
        });
        setStep("otp");
        setOtp("");
        setResendAt(Date.now() + 30000);
    };
    const submitAuth = (e?: React.FormEvent) => {
        e?.preventDefault();
        return run(async () => {
            if (step === "mobile") {
                const check = await request<State>(
                    "/api/v1/customer/check-user",
                    { mobileNumber: mobile },
                );
                if (check.exists) await sendOtp(false);
                else setStep("signup");
            } else if (step === "signup") await sendOtp(true);
            else {
                const result = await request<State>(
                    "/api/v1/customer/verify-otp",
                    { mobileNumber: mobile, otp },
                );
                if (!result.customer || !result.token)
                    throw new Error("Sign-in could not be completed.");
                await bindAccount({
                    customer: result.customer,
                    token: result.token,
                });
            }
        });
    };
    useEffect(() => {
        if (step === "otp" && otp.length === 6) void submitAuth();
    }, [otp]);
    const remaining = Math.max(
        0,
        Math.ceil(
            ((state.expiresAt ? Date.parse(state.expiresAt) : now) -
                (now + offset.current)) /
                1000,
        ),
    );
    const time = `${Math.floor(remaining / 60)
        .toString()
        .padStart(2, "0")}:${(remaining % 60).toString().padStart(2, "0")}`;
    const price = new Intl.NumberFormat("en-IN", {
        style: "currency",
        currency: state.currency || "INR",
    }).format(state.amount);
    const methods = state.enabledMethods || b.enabledPaymentMethods || ["UPI"];
    const needsAuth =
        ready &&
        !state.customer &&
        !terminal(state.status) &&
        state.status !== "BLOCKED" &&
        !duplicate &&
        !!identity;
    const authVisible = sheet === "account" || (needsAuth && !sheet);
    const saved = rememberedAccounts();
    useEffect(() => {
        if (!authVisible && !sheet) return;
        const dialog = document.querySelector<HTMLElement>("[role=dialog]");
        if (!dialog) return;
        const previous = document.activeElement as HTMLElement | null;
        const focusable = () =>
            Array.from(
                dialog.querySelectorAll<HTMLElement>(
                    "button:not(:disabled),input:not(:disabled),a[href]",
                ),
            );
        (dialog.querySelector<HTMLElement>("input") || focusable()[0])?.focus();
        const key = (e: KeyboardEvent) => {
            if (e.key === "Escape") {
                e.preventDefault();
                setSheet("");
            }
            if (e.key === "Tab") {
                const items = focusable(),
                    first = items[0],
                    last = items.at(-1);
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last?.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first?.focus();
                }
            }
        };
        dialog.addEventListener("keydown", key);
        return () => {
            dialog.removeEventListener("keydown", key);
            if (previous?.isConnected) previous.focus();
        };
    }, [authVisible, sheet, step]);

    const paymentDone =
        state.status === "ACTIVE" &&
        state.method === "UPI" &&
        remaining > 0 &&
        remaining <= 240;
    return (
        <div
            className="checkout"
            data-payment-status={state.status}
            style={
                {
                    "--primary": /^#[\da-f]{6}$/i.test(b.primaryColor)
                        ? b.primaryColor
                        : "#2563eb",
                } as React.CSSProperties
            }
        >
            <aside className={`summary ${b.headerTheme}`}>
                <div className="merchant">
                    <span className="logo">
                        {safeUrl(b.logoUrl) ? (
                            <img src={safeUrl(b.logoUrl)} alt="" />
                        ) : (
                            state.merchantName?.slice(0, 1)
                        )}
                    </span>
                    <div>
                        <h1>{state.merchantName}</h1>
                        {!b.hideTrustBadge && (
                            <p>
                                ✓{" "}
                                {b.trustBadgeText ||
                                    `${b.appName} Verified Business`}
                            </p>
                        )}
                    </div>
                    <button
                        className="icon"
                        aria-label="Switch account"
                        onClick={() => {
                            setSheet("account");
                            setStep("mobile");
                        }}
                    >
                        ♙
                    </button>
                </div>
                <div className="desktop-order">
                    <span>Total amount</span>
                    <h2>{price}</h2>
                    <p>Order #{state.orderId}</p>
                    <button onClick={() => setSheet("details")}>
                        View order details
                    </button>
                    <p className="security">♢ {b.sidebarNotice}</p>
                </div>
            </aside>
            <main>
                <div className="toolbar">
                    <span>
                        {!b.hideSubbanner &&
                            (b.subbannerText ||
                                `Verified & Protected by ${b.appName}`)}
                    </span>
                    <button aria-label="Help" onClick={() => setSheet("help")}>
                        Help
                    </button>
                    <button
                        aria-label="Close checkout"
                        onClick={() => setSheet("cancel")}
                    >
                        ×
                    </button>
                </div>
                {!ready ? (
                    <section className="center" aria-live="polite">
                        <div className="spinner" />
                        <h2>Preparing secure checkout…</h2>
                    </section>
                ) : (
                    <>
                        {error && (
                            <div role="alert" className="error">
                                {error}
                                <button onClick={() => void refresh()}>
                                    Check again
                                </button>
                            </div>
                        )}
                        {duplicate ? (
                            <section className="center">
                                <h2>Payment opened on another device</h2>
                                <p>
                                    This payment session was created on another
                                    device. For security reasons, this session
                                    cannot be continued from this device.
                                </p>
                                {!token ? (
                                    <button
                                        className="primary"
                                        onClick={() => {
                                            setSheet("account");
                                            setStep("mobile");
                                        }}
                                    >
                                        Sign in to continue
                                    </button>
                                ) : (
                                    <button
                                        className="primary"
                                        disabled={busy}
                                        onClick={() =>
                                            void action(
                                                "end-and-restart-payment",
                                            )
                                        }
                                    >
                                        End previous session and restart
                                    </button>
                                )}
                            </section>
                        ) : state.status === "BLOCKED" ? (
                            <section className="center blocked">
                                <h2>FRAUDULENT ACTIVITY DETECTED</h2>
                                <h3>
                                    Payment Session Terminated &amp; Account
                                    Suspended
                                </h3>
                                <p>
                                    {state.blockedNotice?.message ||
                                        "Your account has been temporarily blocked because suspicious payment activity was detected. Our team will review the activity before access can be restored."}
                                </p>
                                <p>Case: {id}</p>
                                <button
                                    onClick={() =>
                                        void navigator.clipboard.writeText(
                                            `Payment ${id}, order ${state.orderId}`,
                                        )
                                    }
                                >
                                    Copy case information
                                </button>
                                <button
                                    className="primary"
                                    onClick={() =>
                                        post(
                                            "paykr.payment_blocked_exit",
                                            state,
                                        )
                                    }
                                >
                                    Exit
                                </button>
                            </section>
                        ) : state.status === "SUCCESS" ? (
                            <section className="center success">
                                <span className="success-mark">✓</span>
                                <h2>
                                    {state.method === "COD"
                                        ? "Order Placed (Cash on Delivery)!"
                                        : "Payment successful!"}
                                </h2>
                                <h3>{price}</h3>
                                <p>{state.merchantName}</p>
                                <p>Verified by {b.appName} banking observers</p>
                                {state.method === "COD" && (
                                    <p>
                                        Pay when your order arrives. Your
                                        merchant can check this order using the
                                        COD order API.
                                    </p>
                                )}
                                <p>Order #{state.orderId}</p>
                                {state.utr && <p>Reference: {state.utr}</p>}
                            </section>
                        ) : terminal(state.status) ? (
                            <section className="center">
                                <h2>Payment {state.status?.toLowerCase()}</h2>
                                <p>
                                    {state.status === "CANCELLED"
                                        ? "Your payment session was cancelled."
                                        : "This payment was not completed."}
                                </p>
                                <p>
                                    If your account was debited, contact the
                                    merchant with your payment reference.
                                </p>
                                <button
                                    className="primary"
                                    disabled={busy}
                                    onClick={() =>
                                        void action("retry-new-payment")
                                    }
                                >
                                    Try again
                                </button>
                                <button onClick={() => setSheet("help")}>
                                    Get help
                                </button>
                            </section>
                        ) : (
                            <section className="payment">
                                <nav aria-label="Payment methods">
                                    {methods.map((method) => (
                                        <button
                                            key={method}
                                            aria-pressed={
                                                state.method === method
                                            }
                                            disabled={
                                                busy ||
                                                !state.customer ||
                                                state.status === "PROCESSING"
                                            }
                                            onClick={() =>
                                                void action("select-method", {
                                                    method,
                                                })
                                            }
                                        >
                                            {labels[method] || method}
                                        </button>
                                    ))}
                                </nav>
                                {state.upiServerIndicator?.isWorking ===
                                    false &&
                                    state.method === "UPI" && (
                                        <div className="warning">
                                            <strong>
                                                {state.upiServerIndicator.label}
                                            </strong>
                                            <p>
                                                {state.upiServerIndicator
                                                    .warning ||
                                                    "Bank confirmation may take longer than usual."}
                                            </p>
                                            {methods
                                                .filter(
                                                    (m) =>
                                                        m === "COD" ||
                                                        m === "CASH_COUNTER",
                                                )
                                                .map((m) => (
                                                    <button
                                                        key={m}
                                                        disabled={
                                                            busy ||
                                                            !state.customer
                                                        }
                                                        onClick={() =>
                                                            void action(
                                                                "select-method",
                                                                { method: m },
                                                            )
                                                        }
                                                    >
                                                        Pay via {labels[m]}{" "}
                                                        instead
                                                    </button>
                                                ))}
                                        </div>
                                    )}
                                {state.hasActiveSessionConflict && (
                                    <div className="warning">
                                        Another payment is already in progress.{" "}
                                        <a
                                            href={safeUrl(
                                                state.conflictingSession
                                                    ?.checkoutUrl,
                                            )}
                                        >
                                            Continue that payment
                                        </a>
                                    </div>
                                )}
                                {state.status === "QUEUED" ? (
                                    <div className="center">
                                        <div className="spinner" />
                                        <h2>You’re in the queue</h2>
                                        <p>
                                            Position {state.queuePosition || 1}
                                        </p>
                                        <p>
                                            Estimated wait: up to{" "}
                                            {(state.queuePosition || 1) * 5}{" "}
                                            minutes. This page will update
                                            automatically.
                                        </p>
                                        {!b.hideTimeSavingBanner &&
                                            methods.some(
                                                (m) => m !== "UPI",
                                            ) && (
                                                <p>
                                                    You can choose another
                                                    available payment method
                                                    above instead of waiting.
                                                </p>
                                            )}
                                    </div>
                                ) : state.status === "PROCESSING" ? (
                                    <div className="center">
                                        <div className="spinner" />
                                        <h2>Checking your payment</h2>
                                        <p>
                                            Checking UPI transaction in
                                            real-time…
                                        </p>
                                        <strong className="timer">
                                            {time}
                                        </strong>
                                        <p>Please do not pay again.</p>
                                    </div>
                                ) : state.method === "COD" ? (
                                    <div className="center">
                                        <h2>Cash on Delivery</h2>
                                        <p>
                                            Pay {price} when your order arrives.
                                        </p>
                                        <button
                                            className="primary"
                                            disabled={busy || !state.customer}
                                            onClick={() =>
                                                void action("confirm-cod")
                                            }
                                        >
                                            Place cash on delivery order
                                        </button>
                                    </div>
                                ) : ["CASH_COUNTER", "CARD_COUNTER"].includes(
                                      state.method || "",
                                  ) ? (
                                    <div className="center">
                                        <h2>{labels[state.method!]}</h2>
                                        <h3>{price}</h3>
                                        <p>
                                            {state.counterInstructions ||
                                                (state.method === "CARD_COUNTER"
                                                    ? b.cardCounterInstructions
                                                    : b.counterInstructions)}
                                        </p>
                                        <p>Order #{state.orderId}</p>
                                        <div className="status-pill">
                                            Waiting for cashier confirmation
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <h2>UPI QR Payment</h2>
                                        <div className="row">
                                            <h3>Scan &amp; Pay via UPI</h3>
                                            <span
                                                className="timer"
                                                aria-label="Time remaining"
                                            >
                                                ◷ {time}
                                            </span>
                                        </div>
                                        <div className="qr-card">
                                            {qr ? (
                                                <img
                                                    className="qr"
                                                    src={qr}
                                                    alt="UPI payment QR code"
                                                />
                                            ) : (
                                                <div className="qr placeholder">
                                                    {state.customer
                                                        ? "Preparing QR…"
                                                        : "Sign in to pay"}
                                                </div>
                                            )}
                                            <div>
                                                <h3>
                                                    Scan the QR using any UPI
                                                    App
                                                </h3>
                                                <div className="providers">
                                                    {[
                                                        "gpay",
                                                        "phonepe",
                                                        "paytm",
                                                        "cred",
                                                        "bhim",
                                                        "amazonpay",
                                                    ].map((p) => (
                                                        <img
                                                            key={p}
                                                            src={`/icons/${p}.png`}
                                                            alt={p}
                                                        />
                                                    ))}
                                                </div>
                                                <p>
                                                    Open any UPI app on your
                                                    phone to scan and pay
                                                    directly.
                                                </p>
                                                {state.upiUri && (
                                                    <a
                                                        className="upi-link"
                                                        href={state.upiUri}
                                                    >
                                                        Open UPI app
                                                    </a>
                                                )}
                                            </div>
                                        </div>
                                        {paymentDone && (
                                            <button
                                                className="primary payment-done"
                                                disabled={busy}
                                                onClick={() =>
                                                    void action("payment-done")
                                                }
                                            >
                                                Payment Done
                                            </button>
                                        )}
                                        <ol className="instructions">
                                            <li>
                                                Open any UPI app on your phone.
                                            </li>
                                            <li>
                                                Scan the QR code and pay exactly{" "}
                                                {price}.
                                            </li>
                                            <li>
                                                Keep this page open while your
                                                bank confirms the payment.
                                            </li>
                                        </ol>
                                        {b.checkoutNotice && (
                                            <p>{b.checkoutNotice}</p>
                                        )}
                                    </>
                                )}
                            </section>
                        )}
                    </>
                )}
                <footer>
                    <span>
                        {b.footerGuarantee?.replaceAll("Paykr", b.appName) ||
                            `Secured by ${b.appName}`}
                    </span>
                    {safeUrl(b.privacyPolicyUrl) && (
                        <a
                            href={safeUrl(b.privacyPolicyUrl)}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Privacy notice
                        </a>
                    )}
                    <small data-connection-status>
                        {connection === "live"
                            ? "Live payment updates"
                            : "Checking for updates every 5 seconds"}
                        {!b.hidePoweredBy && ` · Powered by ${b.appName}`}
                    </small>
                </footer>
                <div className="mobile-total">
                    <strong>{price}</strong>
                    <button onClick={() => setSheet("details")}>
                        View details ↑
                    </button>
                </div>
            </main>
            {(authVisible || sheet) && (
                <div className="sheet-backdrop">
                    <section
                        role="dialog"
                        aria-modal="true"
                        aria-label={authVisible ? "Sign in" : sheet}
                        className="sheet"
                    >
                        <button
                            className="sheet-close"
                            aria-label="Close dialog"
                            onClick={() => setSheet("")}
                        >
                            ×
                        </button>
                        {authVisible ? (
                            <>
                                <h2>
                                    {step === "otp"
                                        ? "Verify your WhatsApp number"
                                        : step === "signup"
                                          ? "Create your account"
                                          : "Sign in to pay"}
                                </h2>
                                <p>Continue securely with {b.appName}</p>
                                {step === "mobile" &&
                                    saved.map((a) => (
                                        <button
                                            className="account"
                                            key={a.customer.id}
                                            disabled={busy}
                                            onClick={() => void useAccount(a)}
                                        >
                                            {a.customer.name} ·{" "}
                                            {a.customer.mobileNumber}
                                        </button>
                                    ))}
                                <form onSubmit={submitAuth}>
                                    {step === "mobile" ? (
                                        <label>
                                            WhatsApp mobile number
                                            <input
                                                autoFocus
                                                required
                                                inputMode="tel"
                                                autoComplete="tel"
                                                value={mobile}
                                                onChange={(e) =>
                                                    setMobile(e.target.value)
                                                }
                                                placeholder="10-digit mobile number"
                                            />
                                        </label>
                                    ) : step === "signup" ? (
                                        <>
                                            <label>
                                                Full name
                                                <input
                                                    required
                                                    autoComplete="name"
                                                    value={name}
                                                    onChange={(e) =>
                                                        setName(e.target.value)
                                                    }
                                                />
                                            </label>
                                            <label>
                                                Email address
                                                <input
                                                    required
                                                    type="email"
                                                    autoComplete="email"
                                                    value={email}
                                                    onChange={(e) =>
                                                        setEmail(e.target.value)
                                                    }
                                                />
                                            </label>
                                        </>
                                    ) : (
                                        <>
                                            <p>
                                                Enter the 6-digit code sent to{" "}
                                                {mobile}.
                                            </p>
                                            <label>
                                                Verification code
                                                <input
                                                    autoFocus
                                                    inputMode="numeric"
                                                    autoComplete="one-time-code"
                                                    maxLength={6}
                                                    pattern="[0-9]{6}"
                                                    value={otp}
                                                    onChange={(e) =>
                                                        setOtp(
                                                            e.target.value.replace(
                                                                /\D/g,
                                                                "",
                                                            ),
                                                        )
                                                    }
                                                />
                                            </label>
                                            <button
                                                type="button"
                                                disabled={
                                                    busy || now < resendAt
                                                }
                                                onClick={() =>
                                                    void run(() =>
                                                        sendOtp(!!name),
                                                    )
                                                }
                                            >
                                                {now < resendAt
                                                    ? `Resend in ${Math.ceil((resendAt - now) / 1000)}s`
                                                    : "Resend code"}
                                            </button>
                                        </>
                                    )}
                                    {error && (
                                        <p role="alert" className="error">
                                            {error}
                                        </p>
                                    )}
                                    <button className="primary" disabled={busy}>
                                        {busy
                                            ? "Please wait…"
                                            : step === "otp"
                                              ? "Verify and continue"
                                              : "Continue"}
                                    </button>
                                    {step !== "mobile" && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setStep("mobile");
                                                setOtp("");
                                            }}
                                        >
                                            Change number
                                        </button>
                                    )}
                                </form>
                            </>
                        ) : sheet === "cancel" ? (
                            <>
                                <h2>Cancel this payment?</h2>
                                <p>
                                    If you have already paid, keep this screen
                                    open for bank confirmation.
                                </p>
                                <button
                                    className="primary"
                                    disabled={busy}
                                    onClick={() => void action("cancel")}
                                >
                                    Cancel payment
                                </button>
                                <button onClick={() => setSheet("")}>
                                    Continue payment
                                </button>
                            </>
                        ) : sheet === "details" ? (
                            <>
                                <h2>Order details</h2>
                                <dl>
                                    <dt>Merchant</dt>
                                    <dd>{state.merchantName}</dd>
                                    {customName && (
                                        <>
                                            <dt>Display name</dt>
                                            <dd>{customName}</dd>
                                        </>
                                    )}
                                    {customAmount &&
                                        Number.isFinite(
                                            Number(customAmount),
                                        ) && (
                                            <>
                                                <dt>
                                                    Requested display amount
                                                </dt>
                                                <dd>
                                                    {Number(
                                                        customAmount,
                                                    ).toFixed(2)}
                                                </dd>
                                            </>
                                        )}
                                    <dt>Order</dt>
                                    <dd>{state.orderId}</dd>
                                    <dt>Amount</dt>
                                    <dd>{price}</dd>
                                    <dt>Payment</dt>
                                    <dd>{id}</dd>
                                </dl>
                            </>
                        ) : (
                            <>
                                <h2>Need help?</h2>
                                <p>
                                    Contact {state.merchantName} and include
                                    order #{state.orderId}.
                                </p>
                                {b.supportEmails?.map((e) => (
                                    <p key={e}>
                                        <a href={`mailto:${e}`}>{e}</a>
                                    </p>
                                ))}
                                {b.supportMobiles?.map((m) => (
                                    <p key={m}>
                                        <a href={`tel:${m}`}>{m}</a>
                                    </p>
                                ))}
                                <p>
                                    If your bank confirmation is delayed, do not
                                    pay again. The merchant can review the
                                    credit when it arrives.
                                </p>
                                {safeUrl(b.termsUrl) && (
                                    <p>
                                        <a
                                            href={safeUrl(b.termsUrl)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Terms and conditions
                                        </a>
                                    </p>
                                )}
                                {safeUrl(b.privacyPolicyUrl) && (
                                    <a
                                        href={safeUrl(b.privacyPolicyUrl)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Privacy policy
                                    </a>
                                )}
                            </>
                        )}
                    </section>
                </div>
            )}
        </div>
    );
}
createRoot(document.getElementById("root")!).render(<App />);
