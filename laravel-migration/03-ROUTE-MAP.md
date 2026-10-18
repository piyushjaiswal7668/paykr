# Route map — every current Payment API route and where it goes in Laravel

Generated from Appendix A1. One row per route; nothing may be left unmapped. "Same path" routes live in `routes/api.php` (or `routes/web.php` for pages) with the listed auth.

| # | Method | Current path | Laravel location | Auth | Spec |
|---|---|---|---|---|---|
| 1 | GET | `/health` | Same path (`routes/api.php`, HealthController) | none | §6.1 |
| 2 | GET | `/api/v1/health` | Same path (`routes/api.php`, HealthController) | none | §6.1 |
| 3 | GET | `/api/v1/admin/queue` | Merchant ▸ Live monitor (current slot + queue widgets) | merchant: any role | §9 |
| 4 | GET | `/api/v1/admin/observers` | Merchant ▸ Observers page | merchant: any role | §9 |
| 5 | GET | `/api/v1/admin/payments` | Merchant ▸ Transactions resource | merchant: any role | §9 |
| 6 | GET | `/api/v1/admin/tickets` | Merchant ▸ Support tickets resource | owner, manager | §5.17 |
| 7 | POST | `/api/v1/admin/tickets/:id/resolve` | Merchant ▸ Support tickets action "Mark resolved" | owner, manager | §5.17 |
| 8 | POST | `/api/v1/admin/counter-confirm` | Merchant ▸ Counter desk action "Confirm" (409 if closed) | owner, manager, cashier | §5.14 |
| 9 | POST | `/api/v1/admin/counter-decline` | Merchant ▸ Counter desk action "Decline" (409 if closed — FIX) | owner, manager, cashier | §5.14 |
| 10 | GET | `/api/v1/admin/upi-settings` | Merchant ▸ Receiving accounts (active VPA) | owner, manager | §9 |
| 11 | GET | `/api/v1/admin/upi-accounts` | Merchant ▸ Receiving accounts resource (list / create) | owner, manager | §9 |
| 12 | POST | `/api/v1/admin/upi-accounts` | Merchant ▸ Receiving accounts resource (list / create) | owner, manager | §9 |
| 13 | POST | `/api/v1/admin/upi-accounts/:id/activate` | Receiving accounts action "Activate" (deactivates others atomically) | owner, manager | §3.1, §9 |
| 14 | POST | `/api/v1/admin/upi-accounts/:id/toggle` | Receiving accounts action "Activate/Deactivate" | owner, manager | §9 |
| 15 | DELETE | `/api/v1/admin/upi-accounts/:id` | Receiving accounts action "Delete" (blocked while slot busy) | owner, manager | §9 |
| 16 | POST | `/api/v1/admin/upi-settings` | Merchant ▸ Receiving accounts (active VPA) | owner, manager | §9 |
| 17 | GET | `/api/v1/admin/all-settings` | Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages | owner, manager (webhooks: owner, developer) | §9 |
| 18 | POST | `/api/v1/admin/all-settings` | Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages | owner, manager (webhooks: owner, developer) | §9 |
| 19 | GET | `/api/v1/admin/merchant-settings` | Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages | owner, manager (webhooks: owner, developer) | §9 |
| 20 | POST | `/api/v1/admin/merchant-settings` | Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages | owner, manager (webhooks: owner, developer) | §9 |
| 21 | GET | `/api/v1/admin/cod-orders` | Merchant ▸ Transactions (filter method = COD) / COD orders page | merchant: any role | §9 |
| 22 | GET | `/api/v1/admin/stream` | Reverb private channel `merchant.{id}.live` | merchant user | §7 |
| 23 | GET | `/api/v1/admin/whatsapp/status` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 24 | GET | `/api/v1/admin/whatsapp/qr` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 25 | POST | `/api/v1/admin/whatsapp/reconnect` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 26 | POST | `/api/v1/admin/whatsapp/pairing-code` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 27 | POST | `/api/v1/admin/whatsapp/disconnect` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 28 | POST | `/api/v1/admin/whatsapp/test-message` | Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) | platform admin | §8, §14.1 |
| 29 | POST | `/api/v1/admin/queue/clear` | Merchant ▸ Live monitor action "Clear waiting queue" (this merchant only) | owner, manager | §9, §2.2.5 |
| 30 | GET | `/api/v1/admin/counter-pending` | Merchant ▸ Counter desk (live table) | owner, manager, cashier | §5.14, §9 |
| 31 | GET | `/api/v1/admin/tunnel/status` | DROPPED (shell execution) | — | §18 |
| 32 | POST | `/api/v1/admin/tunnel/start` | DROPPED (shell execution) | — | §18 |
| 33 | POST | `/api/v1/admin/tunnel/stop` | DROPPED (shell execution) | — | §18 |
| 34 | GET | `/api/v1/admin/gmail/credentials` | Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser | owner, manager, developer | §15 |
| 35 | GET | `/api/v1/admin/gmail/oauth/start` | Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser | owner, manager, developer | §15 |
| 36 | POST | `/api/v1/admin/gmail/disconnect` | Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser | owner, manager, developer | §15 |
| 37 | POST | `/api/v1/admin/gmail/credentials` | Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser | owner, manager, developer | §15 |
| 38 | POST | `/api/v1/admin/gmail/test` | Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser | owner, manager, developer | §15 |
| 39 | GET | `/api/v1/admin/dev/false-narrative` | DROPPED (misleading customers) | — | §18 |
| 40 | POST | `/api/v1/admin/dev/false-narrative` | DROPPED (misleading customers) | — | §18 |
| 41 | GET | `/api/v1/admin/dev/test-mode` | Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) | owner, developer | §5.16 |
| 42 | POST | `/api/v1/admin/dev/test-mode` | Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) | owner, developer | §5.16 |
| 43 | POST | `/api/v1/admin/dev/test-mode/inject` | Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) | owner, developer | §5.16 |
| 44 | GET | `/api/v1/admin/dev/test-mode/audit` | Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) | owner, developer | §5.16 |
| 45 | GET | `/api/v1/admin/dev/simulator/customers` | Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) | owner, developer | §5.16 |
| 46 | GET | `/api/v1/admin/dev/simulator/customers/:id/sessions` | Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) | owner, developer | §5.16 |
| 47 | POST | `/api/v1/admin/dev/simulator/inject` | Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) | owner, developer | §5.16 |
| 48 | GET | `/api/v1/admin/dev/simulator/history` | Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) | owner, developer | §5.16 |
| 49 | GET | `/api/v1/admin/security/banned-users` | Platform admin ▸ Security ▸ Banned users (+ evidence page); merchant sees own blocks | platform admin | §5.12, §8 |
| 50 | GET | `/api/v1/admin/security/banned-users/:id` | Platform admin ▸ Security ▸ Banned users (+ evidence page); merchant sees own blocks | platform admin | §5.12, §8 |
| 51 | POST | `/api/v1/admin/security/unban` | Platform admin ▸ Customers / Security actions "Ban" / "Unban" (merchant: "Block for my store") | platform admin (merchant: owner, manager for blocks) | §5.12, §2.2.4 |
| 52 | POST | `/api/v1/admin/security/manual-ban` | Platform admin ▸ Customers / Security actions "Ban" / "Unban" (merchant: "Block for my store") | platform admin (merchant: owner, manager for blocks) | §5.12, §2.2.4 |
| 53 | GET | `/api/v1/admin/security/events` | Platform admin ▸ Security events / Ban history; merchant ▸ own security events | platform admin | §5.12 |
| 54 | GET | `/api/v1/admin/security/history` | Platform admin ▸ Security events / Ban history; merchant ▸ own security events | platform admin | §5.12 |
| 55 | GET | `/api/v1/admin/customers` | Platform admin ▸ Customers resource | platform admin | §8 |
| 56 | POST | `/api/v1/admin/auth/login` | Filament login / logout (both panels) + 2FA | session | §6.3 |
| 57 | POST | `/api/v1/admin/auth/logout` | Filament login / logout (both panels) + 2FA | session | §6.3 |
| 58 | GET | `/api/v1/admin/auth/me` | Filament auth (no endpoint needed) | session | §6.3 |
| 59 | GET | `/api/v1/checkout/:id/state` | Same path | device access (§5.5) | §5.8 |
| 60 | GET | `/api/v1/checkout/server-health` | Same path (per merchant) | none | §5.15 |
| 61 | POST | `/api/v1/checkout/:id/bind-customer` | Same path | device access (§5.5) | §5.8 |
| 62 | POST | `/api/v1/checkout/:id/retry-new-payment` | Same path | device access (§5.5) | §5.8 |
| 63 | POST | `/api/v1/checkout/:id/payment-done` | Same path | device access (§5.5) | §5.8 |
| 64 | POST | `/api/v1/checkout/:id/cancel` | Same path | device access (§5.5) | §5.8 |
| 65 | POST | `/api/v1/checkout/:id/select-method` | Same path | device access (§5.5) | §5.8 |
| 66 | POST | `/api/v1/checkout/:id/confirm-cod` | Same path | device access (§5.5) | §5.8 |
| 67 | GET | `/api/v1/checkout/:id/cod` | Same path | device access (§5.5) | §5.8 |
| 68 | POST | `/api/v1/checkout/:id/expire` | Same path | device access (§5.5) | §5.8 |
| 69 | POST | `/api/v1/checkout/:id/trigger-hook` | Same path | device access (§5.5) | §5.8 |
| 70 | POST | `/api/v1/checkout/:id/simulate-observation` | Same path | merchant developer/owner or platform admin + active test mode | §5.8, §5.16 |
| 71 | POST | `/api/v1/checkout/:id/end-and-restart-payment` | Same path (both aliases) | device id | §5.8 |
| 72 | POST | `/api/v1/checkout/:id/end-session-and-create-new` | Same path (both aliases) | device id | §5.8 |
| 73 | GET | `/api/v1/checkout/:id/stream` | Reverb private channel `payment.{id}` (keep path → 410 or SSE fallback) | device access | §7 |
| 74 | GET | `/api/v1/checkout/:id/events` | Reverb private channel `payment.{id}` (keep path → 410 or SSE fallback) | device access | §7 |
| 75 | POST | `/api/v1/customer/check-user` | Same path | none, rate-limited | §5.4 |
| 76 | POST | `/api/v1/customer/send-otp` | Same path | none, rate-limited | §5.4 |
| 77 | POST | `/api/v1/customer/verify-otp` | Same path | none, rate-limited | §5.4 |
| 78 | POST | `/api/v1/customer/register` | Same path | none, rate-limited | §5.4 |
| 79 | POST | `/api/v1/customer/login` | Same path | none, rate-limited | §5.4 |
| 80 | GET | `/api/v1/customer/me` | Same path | customer token | §5.4 |
| 81 | POST | `/api/v1/customer/logout` | Same path | customer token | §5.4 |
| 82 | POST | `/api/v1/customer/switch-account` | Same path | customer token | §5.4 |
| 83 | GET | `/api/v1/customer/payments` | NOT PORTED (decommissioned: returns 404 today) | — | §5.4, §18 |
| 84 | POST | `/api/v1/customer/tickets` | NOT PORTED (decommissioned: returns 404 today) | — | §5.4, §18 |
| 85 | GET | `/api/v1/customer/tickets/:id/messages` | NOT PORTED (decommissioned: returns 404 today) | — | §5.4, §18 |
| 86 | POST | `/api/v1/customer/tickets/:id/messages` | NOT PORTED (decommissioned: returns 404 today) | — | §5.4, §18 |
| 87 | GET | `/api/v1/customer/tickets/:id/stream` | NOT PORTED (decommissioned: returns 404 today) | — | §5.4, §18 |
| 88 | POST | `/api/v1/devices/register` | Same path | none, rate-limited | §5.5 |
| 89 | POST | `/api/v1/devices/verify` | Same path | none, rate-limited | §5.5 |
| 90 | GET | `/api/v1/devices/:id` | Same path | own customer token / platform admin — FIX | §5.5 |
| 91 | GET | `/api/v1/devices/customer/:customerId` | Same path | own customer token / platform admin — FIX | §5.5 |
| 92 | POST | `/api/v1/payments` | Same path | secret or publishable key | §5.3 |
| 93 | GET | `/api/v1/payments/:id` | Same path | secret key (own merchant) — FIX | §5.3 |
| 94 | GET | `/api/v1/payments/:id/cod` | Same path | secret key (own merchant) — FIX | §5.3 |
| 95 | GET | `/api/v1/orders/:orderId/cod` | Same path | secret key (own merchant) — FIX | §5.3 |
| 96 | GET | `/api/v1/pollers/active-window` | `GET /internal/active-window?merchantId=` (sidecar only) | internal token | §6.2 |
| 97 | POST | `/api/v1/pollers/events` | `POST /internal/observations` (sidecar only; pipeline is final) | internal token | §6.2, §5.10 |
| 98 | POST | `/api/v1/pollers/android/pair` | Same path (single-use pairing token) | pairing token | §13 |
| 99 | POST | `/api/v1/observers/sms/pair` | Same path (single-use pairing token) | pairing token | §13 |
| 100 | POST | `/api/v1/pollers/android/events` | Same path (mandatory HMAC; pipeline is final) | device HMAC | §13, §5.10 |
| 101 | POST | `/api/v1/observers/sms` | Same path (mandatory HMAC; pipeline is final) | device HMAC | §13, §5.10 |
| 102 | POST | `/api/v1/pollers/android/heartbeat` | Same path | device id | §13 |
| 103 | POST | `/api/v1/pollers/heartbeat` | Same path | device id | §13 |
| 104 | POST | `/api/v1/observers/heartbeat` | Same path | device id | §13 |
| 105 | GET | `/api/v1/admin/sms-gateway/pair-qr` | Merchant ▸ Developer ▸ Android SMS (single-use QR) | owner, manager, developer | §13 |
| 106 | GET | `/api/v1/admin/sms-gateway/devices` | Merchant ▸ Developer ▸ Android SMS (device list) | owner, manager, developer | §13 |
| 107 | DELETE | `/api/v1/admin/sms-gateway/devices/:deviceId` | Android SMS action "Unpair" | owner, manager, developer | §13 |
| 108 | GET | `/api/v1/admin/sms-gateway/download-apk` | Same path (serves APK from storage; never a stub) | none | §6.1, §13 |
| 109 | GET | `/paykr-sms-gateway.apk` | Same path (serves APK from storage; never a stub) | none | §6.1, §13 |

## Other services

| Current | Laravel location |
|---|---|
| Checkout portal `GET /checkout/:id` | `routes/web.php` → React checkout shell (§10) |
| Checkout portal `GET /checkout/:id/qr.svg` | `routes/web.php`, device access, 404 unless ACTIVE (§6.1) |
| Checkout portal `GET /paykr.js`, `/sdk/paykr.js`, `/sdk/paykr-checkout.js` | `routes/web.php`, static file with CORS (§11) |
| Checkout portal `GET /icons/*` | `public/icons` |
| Checkout portal `/api/*` proxy | not needed (same app) |
| Merchant admin `GET /`, `/admin`, `/login` | Filament `/merchant` and `/admin` panels |
| Merchant admin `/api/admin/tickets` | Merchant ▸ Support tickets |
| Merchant admin `/api/services*`, `/api/logs/stream` (Control Center proxy) | Dropped; Platform admin ▸ System page |
| Merchant admin `/paykr-sms-gateway.apk` | same path |
| Control Center (all routes) | Dropped (§18) |
| Gmail observer `/health`, `/stop`, `/oauth/url`, `/oauth/callback`, `/api/browser/*`, `/api/sweep`, `/process-email` | Laravel Gmail OAuth + jobs (§15); browser automator dropped |
| SMS observer `/health`, `/api/browser/status`, `/api/browser/auto-ensure`, `/api/browser/launch-pairing`, `/api/sweep`, `/process-sms` | Messages sidecar `/profiles/{merchantId}/status|pair|sweep` (§14.2) |
| Help portal `/`, `/support`, `/help`, `/api/customer/tickets` | Help modal in checkout; tickets endpoint decommissioned |
| Demo store (all routes) | Separate example project (§19.3) |

Unmapped routes: 0
