# Migration parity ledger

Source: `/Applications/XAMPP/xamppfiles/htdocs/YouPI-PG`. Destination: `/Applications/XAMPP/xamppfiles/htdocs/Paykr`.

Only implemented and verified behavior is checked. Items replaced or dropped still need an explicit disposition before closure.

## Payment API routes

- [ ] Route 1: GET `/health` → Same path (`routes/api.php`, HealthController) — Phase 0 health smoke test; full legacy health payload review remains Phase 3.
- [ ] Route 2: GET `/api/v1/health` → Same path (`routes/api.php`, HealthController) — Phase 0 health smoke test; full legacy health payload review remains Phase 3.
- [x] Route 3: GET `/api/v1/admin/queue` → Merchant ▸ Live monitor (current slot + queue widgets) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 4: GET `/api/v1/admin/observers` → Merchant ▸ Observers page — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 5: GET `/api/v1/admin/payments` → Merchant ▸ Transactions resource — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 6: GET `/api/v1/admin/tickets` → Merchant ▸ Support tickets resource — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 7: POST `/api/v1/admin/tickets/:id/resolve` → Merchant ▸ Support tickets action "Mark resolved" — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 8: POST `/api/v1/admin/counter-confirm` → Merchant ▸ Counter desk action "Confirm" (409 if closed) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 9: POST `/api/v1/admin/counter-decline` → Merchant ▸ Counter desk action "Decline" (409 if closed — FIX) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 10: GET `/api/v1/admin/upi-settings` → Merchant ▸ Receiving accounts (active VPA) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 11: GET `/api/v1/admin/upi-accounts` → Merchant ▸ Receiving accounts resource (list / create) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 12: POST `/api/v1/admin/upi-accounts` → Merchant ▸ Receiving accounts resource (list / create) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 13: POST `/api/v1/admin/upi-accounts/:id/activate` → Receiving accounts action "Activate" (deactivates others atomically) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 14: POST `/api/v1/admin/upi-accounts/:id/toggle` → Receiving accounts action "Activate/Deactivate" — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 15: DELETE `/api/v1/admin/upi-accounts/:id` → Receiving accounts action "Delete" (blocked while slot busy) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 16: POST `/api/v1/admin/upi-settings` → Merchant ▸ Receiving accounts (active VPA) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 17: GET `/api/v1/admin/all-settings` → Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 18: POST `/api/v1/admin/all-settings` → Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 19: GET `/api/v1/admin/merchant-settings` → Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 20: POST `/api/v1/admin/merchant-settings` → Merchant ▸ Brand identity, Payment methods, Webhooks & API keys pages — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 21: GET `/api/v1/admin/cod-orders` → Merchant ▸ Transactions (filter method = COD) / COD orders page — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [ ] Route 22: GET `/api/v1/admin/stream` → Reverb private channel `merchant.{id}.live`
- [x] Route 23: GET `/api/v1/admin/whatsapp/status` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 24: GET `/api/v1/admin/whatsapp/qr` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 25: POST `/api/v1/admin/whatsapp/reconnect` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 26: POST `/api/v1/admin/whatsapp/pairing-code` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 27: POST `/api/v1/admin/whatsapp/disconnect` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 28: POST `/api/v1/admin/whatsapp/test-message` → Platform admin ▸ WhatsApp sender page → WhatsApp sidecar (§14.1) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php (WhatsApp sender)`.
- [x] Route 29: POST `/api/v1/admin/queue/clear` → Merchant ▸ Live monitor action "Clear waiting queue" (this merchant only) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 30: GET `/api/v1/admin/counter-pending` → Merchant ▸ Counter desk (live table) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [ ] Route 31: GET `/api/v1/admin/tunnel/status` → DROPPED (shell execution)
- [ ] Route 32: POST `/api/v1/admin/tunnel/start` → DROPPED (shell execution)
- [ ] Route 33: POST `/api/v1/admin/tunnel/stop` → DROPPED (shell execution)
- [x] Route 34: GET `/api/v1/admin/gmail/credentials` → Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 35: GET `/api/v1/admin/gmail/oauth/start` → Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 36: POST `/api/v1/admin/gmail/disconnect` → Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 37: POST `/api/v1/admin/gmail/credentials` → Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 38: POST `/api/v1/admin/gmail/test` → Merchant ▸ Developer ▸ Gmail (OAuth connect / test / disconnect); credentials never returned to the browser — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [ ] Route 39: GET `/api/v1/admin/dev/false-narrative` → DROPPED (misleading customers)
- [ ] Route 40: POST `/api/v1/admin/dev/false-narrative` → DROPPED (misleading customers)
- [x] Route 41: GET `/api/v1/admin/dev/test-mode` → Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 42: POST `/api/v1/admin/dev/test-mode` → Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 43: POST `/api/v1/admin/dev/test-mode/inject` → Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 44: GET `/api/v1/admin/dev/test-mode/audit` → Merchant ▸ Developer ▸ Payment simulator (test mode toggle, inject, audit list) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 45: GET `/api/v1/admin/dev/simulator/customers` → Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 46: GET `/api/v1/admin/dev/simulator/customers/:id/sessions` → Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 47: POST `/api/v1/admin/dev/simulator/inject` → Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 48: GET `/api/v1/admin/dev/simulator/history` → Merchant ▸ Developer ▸ Payment simulator (customers of this merchant, sessions, inject, history) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 49: GET `/api/v1/admin/security/banned-users` → Platform admin ▸ Security ▸ Banned users (+ evidence page); merchant sees own blocks — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 50: GET `/api/v1/admin/security/banned-users/:id` → Platform admin ▸ Security ▸ Banned users (+ evidence page); merchant sees own blocks — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 51: POST `/api/v1/admin/security/unban` → Platform admin ▸ Customers / Security actions "Ban" / "Unban" (merchant: "Block for my store") — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 52: POST `/api/v1/admin/security/manual-ban` → Platform admin ▸ Customers / Security actions "Ban" / "Unban" (merchant: "Block for my store") — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 53: GET `/api/v1/admin/security/events` → Platform admin ▸ Security events / Ban history; merchant ▸ own security events — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 54: GET `/api/v1/admin/security/history` → Platform admin ▸ Security events / Ban history; merchant ▸ own security events — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 55: GET `/api/v1/admin/customers` → Platform admin ▸ Customers resource — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 56: POST `/api/v1/admin/auth/login` → Filament login / logout (both panels) + 2FA — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 57: POST `/api/v1/admin/auth/logout` → Filament login / logout (both panels) + 2FA — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 58: GET `/api/v1/admin/auth/me` → Filament auth (no endpoint needed) — Phase 8: `tests/Feature/Filament/AdminPanelsTest.php; ManagementServicesTest.php; PanelAccessTest.php`.
- [x] Route 59: GET `/api/v1/checkout/:id/state` → Same path
- [x] Route 60: GET `/api/v1/checkout/server-health` → Same path (per merchant)
- [x] Route 61: POST `/api/v1/checkout/:id/bind-customer` → Same path
- [x] Route 62: POST `/api/v1/checkout/:id/retry-new-payment` → Same path
- [x] Route 63: POST `/api/v1/checkout/:id/payment-done` → Same path
- [x] Route 64: POST `/api/v1/checkout/:id/cancel` → Same path
- [x] Route 65: POST `/api/v1/checkout/:id/select-method` → Same path
- [x] Route 66: POST `/api/v1/checkout/:id/confirm-cod` → Same path
- [x] Route 67: GET `/api/v1/checkout/:id/cod` → Same path
- [x] Route 68: POST `/api/v1/checkout/:id/expire` → Same path
- [x] Route 69: POST `/api/v1/checkout/:id/trigger-hook` → Same path
- [x] Route 70: POST `/api/v1/checkout/:id/simulate-observation` → Same path
- [x] Route 71: POST `/api/v1/checkout/:id/end-and-restart-payment` → Same path (both aliases)
- [x] Route 72: POST `/api/v1/checkout/:id/end-session-and-create-new` → Same path (both aliases)
- [x] Route 73: GET `/api/v1/checkout/:id/stream` → Reverb private channel `payment.{id}` (keep path → 410 or SSE fallback)
- [x] Route 74: GET `/api/v1/checkout/:id/events` → Reverb private channel `payment.{id}` (keep path → 410 or SSE fallback)
- [x] Route 75: POST `/api/v1/customer/check-user` → Same path
- [x] Route 76: POST `/api/v1/customer/send-otp` → Same path
- [x] Route 77: POST `/api/v1/customer/verify-otp` → Same path
- [x] Route 78: POST `/api/v1/customer/register` → Same path
- [x] Route 79: POST `/api/v1/customer/login` → Same path
- [x] Route 80: GET `/api/v1/customer/me` → Same path
- [x] Route 81: POST `/api/v1/customer/logout` → Same path
- [x] Route 82: POST `/api/v1/customer/switch-account` → Same path
- [ ] Route 83: GET `/api/v1/customer/payments` → NOT PORTED (decommissioned: returns 404 today)
- [ ] Route 84: POST `/api/v1/customer/tickets` → NOT PORTED (decommissioned: returns 404 today)
- [ ] Route 85: GET `/api/v1/customer/tickets/:id/messages` → NOT PORTED (decommissioned: returns 404 today)
- [ ] Route 86: POST `/api/v1/customer/tickets/:id/messages` → NOT PORTED (decommissioned: returns 404 today)
- [ ] Route 87: GET `/api/v1/customer/tickets/:id/stream` → NOT PORTED (decommissioned: returns 404 today)
- [x] Route 88: POST `/api/v1/devices/register` → Same path
- [x] Route 89: POST `/api/v1/devices/verify` → Same path
- [x] Route 90: GET `/api/v1/devices/:id` → Same path
- [x] Route 91: GET `/api/v1/devices/customer/:customerId` → Same path
- [x] Route 92: POST `/api/v1/payments` → Same path
- [x] Route 93: GET `/api/v1/payments/:id` → Same path
- [x] Route 94: GET `/api/v1/payments/:id/cod` → Same path
- [x] Route 95: GET `/api/v1/orders/:orderId/cod` → Same path
- [x] Route 96: GET `/api/v1/pollers/active-window` → `GET /internal/active-window?merchantId=` (sidecar only)
- [x] Route 97: POST `/api/v1/pollers/events` → `POST /internal/observations` (sidecar only; pipeline is final)
- [x] Route 98: POST `/api/v1/pollers/android/pair` → Same path (single-use pairing token)
- [x] Route 99: POST `/api/v1/observers/sms/pair` → Same path (single-use pairing token)
- [x] Route 100: POST `/api/v1/pollers/android/events` → Same path (mandatory HMAC; pipeline is final)
- [x] Route 101: POST `/api/v1/observers/sms` → Same path (mandatory HMAC; pipeline is final)
- [x] Route 102: POST `/api/v1/pollers/android/heartbeat` → Same path
- [x] Route 103: POST `/api/v1/pollers/heartbeat` → Same path
- [x] Route 104: POST `/api/v1/observers/heartbeat` → Same path
- [x] Route 105: GET `/api/v1/admin/sms-gateway/pair-qr` → Merchant ▸ Developer ▸ Android SMS (single-use QR) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 106: GET `/api/v1/admin/sms-gateway/devices` → Merchant ▸ Developer ▸ Android SMS (device list) — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 107: DELETE `/api/v1/admin/sms-gateway/devices/:deviceId` → Android SMS action "Unpair" — Phase 8: `tests/Feature/Filament/MerchantPanelsTest.php; ManagementServicesTest.php`.
- [x] Route 108: GET `/api/v1/admin/sms-gateway/download-apk` → Same path (serves APK from storage; never a stub)
- [x] Route 109: GET `/paykr-sms-gateway.apk` → Same path (serves APK from storage; never a stub)

## Existing tests (Appendix A8)

### apps/android-observer-app/__tests__/android-observer.test.js
- [ ] rejects pairing with invalid enrollment token
- [ ] successfully pairs Android device with valid enrollment token
- [ ] sends heartbeat acknowledging liveness
- [ ] rejects observation with expired/drifted timestamp (Replay Protection)
- [ ] submits valid HMAC-signed observation and confirms payment in Payment API
### apps/checkout-portal/src/__tests__/checkout-portal.test.js
- [ ] generateQrMatrix creates 29x29 matrix with finder patterns
- [ ] generateQrSvg creates clean, valid SVG markup
- [ ] GET /health returns UP
- [x] GET /checkout/:id renders responsive HTML with injected payment ID — Phase 9 checkout feature/browser checks
- [x] GET /checkout/:id/qr.svg returns dynamic SVG image — Phase 9 checkout feature/browser checks
- [ ] GET /icons/:filename returns official Play Store icons with correct MIME type
- [x] GET /paykr.js and /sdk/paykr-checkout.js return official integration SDK with CORS — Phase 9 checkout feature/browser checks
- [ ] GET /checkout/:id includes PC 2-column sidebar, UPI Server indicator, and NO visual UPI ID in summary
### apps/coin-wallet-store/src/__tests__/coin-wallet-store.test.js
- [ ] GET /health returns UP and initial balance
- [ ] GET / renders Paykr Store product page with gateway showcase and demo checkout
- [ ] POST /api/checkout initiates payment gateway session and returns checkoutUrl
- [ ] POST /api/webhook processes signed SUCCESS event and credits wallet coins
- [ ] POST /api/webhook rejects fraudulent signature
- [ ] GET /wallet renders wallet page with updated balance
- [ ] custom top-up: initiates custom amount payment and credits calculated coins on webhook
- [ ] minimum amount: allows exactly ₹1.00 top-up and credits 1 coin
- [ ] minimum amount: rejects top-up of less than ₹1.00
- [ ] GET /order-failed renders failed order page with deep link to support
- [ ] POST /api/verify-payment credits coins directly from popup drawer and prevents duplicates
- [ ] GET /order-success renders verified payment page with parameters
- [ ] GET /order-suspicious renders held for review security notice
- [ ] GET /order-expired renders expired session notice
- [ ] GET /order-cancelled renders cancelled session notice
- [ ] GET /paykr.js is served with CORS for universal frontend integration
- [ ] Reverse proxy: proxies /api/v1/* requests to payment API directly
- [ ] Reverse proxy: /checkout/* does not return 404 Route Not Found
### apps/control-center/src/__tests__/control-center.test.js
- [ ] GET /health returns UP
- [ ] GET / renders operational console HTML with all components
- [ ] GET /api/services enumerates all modular host services
- [ ] Log buffer captures entries and limits size
- [ ] CRITICAL INVARIANT: Stop All strictly stops child processes and NEVER terminates Control Center
### apps/gmail-observer/src/__tests__/gmail-observer.test.js
- [ ] correctly parses HDFC credit notification email
- [ ] correctly parses custom or alt-account emails like "20.00 received"
- [ ] ignores debit notification emails
- [ ] GET /health returns UP
- [ ] GET /oauth/url returns valid Google OAuth URL
- [ ] End-to-End: processing bank email confirms active payment session in Payment API
### apps/gmail-observer/src/__tests__/oauth-view.test.js
- [ ] OAuth errors are responsive and escape provider text
- [ ] OAuth success preserves its notification without allowing script injection
### apps/merchant-admin/src/__tests__/merchant-admin.test.js
- [ ] GET /health returns UP
- [ ] GET / without a session redirects to the login page
- [ ] GET /login renders the sign-in form
- [ ] GET /login with a valid session goes straight to the dashboard
- [ ] local admin APIs reject requests without a session
- [ ] GET / renders dashboard with queue monitor, observers, counter desk & pairing modal
- [ ] GET /api/admin/tickets returns tickets array
### apps/payment-api/src/__tests__/admin-auth-and-final-states.test.js
- [ ] admin endpoints reject requests without a session
- [ ] login rejects a wrong password and accepts the configured admin
- [ ] simulate-observation and observer ingest need an admin or an internal service token
- [ ] customer-facing proxies refuse admin, observer-ingest and simulation paths
- [ ] phones pair only with a fresh single-use QR token; old hard-coded tokens are rejected
- [ ] a payment cancelled during the verification grace window stays CANCELLED (no SUCCESS, no webhook)
- [ ] closed sessions cannot be confirmed at the counter or turned into SUCCESS by the repository
### apps/payment-api/src/__tests__/cod-parallel-timer-decoupling.test.js
- [x] Switching from UPI to COD decouples UPI queue, promotes queued customer, and starts parallel 5-min timer — domain counterpart: `tests/Feature/Clock/ClockTest.php`; HTTP integration remains Phase 4.
- [x] COD session expires after parallel 5-minute window without touching UPI queue — domain counterpart: `tests/Feature/Clock/ClockTest.php`; HTTP integration remains Phase 4.
### apps/payment-api/src/__tests__/counter-payments.test.js
- [ ] Cash at Counter does NOT block the digital UPI queue slot
- [ ] Card at Counter manual confirmation flow
- [ ] Customer switches from UPI to Cash at Counter via /select-method: unblocks UPI queue and reflects instructions
- [ ] Merchant declines counter payment via /counter-decline: updates to FAILED, broadcasts status_change, logs audit
### apps/payment-api/src/__tests__/developer-payment-simulator.test.js
- [ ] 34. Security: Unauthorized users and customers cannot call simulator endpoints
- [ ] 1, 2, 3. Developer can search existing customer, list sessions, and identify active session
- [ ] 36. Developer Test Mode requirement: simulation injection is blocked when Test Mode is OFF
- [ ] 6. Active Session Requirement: terminal sessions (CANCELLED, EXPIRED, SUCCESS) reject injection with SESSION NO LONGER ACTIVE
- [ ] 4, 5, 6, 10, 11, 31. SMS Stream: accepts arbitrary sender, free-form raw content preserved verbatim, arbitrary timestamp
- [ ] 7, 8, 9, 32. Gmail Stream: accepts arbitrary sender email, free-form content, arbitrary timestamp
- [ ] 14. Invalid SMS sender (personal mobile not matching customer) is rejected by REAL Stage 2
- [ ] 15. Invalid Gmail sender (unrecognized domain) is rejected by REAL Stage 2
- [ ] 16. Missing currency indicator is rejected by REAL Stage 3
- [ ] 17, 18, 19, 20. All currency indicators (Rs, Rs., ₹, INR) pass Stage 3
- [ ] 21. Missing credit indicator is rejected by REAL Stage 3
- [ ] 22. Missing 12-digit reference is rejected by REAL Stage 3
- [ ] 23. Wrong amount is rejected by REAL Stage 4
- [ ] 24. Message received before session creation is rejected by REAL Stage 4
- [ ] 25. Message received after session expiry is rejected by REAL Stage 4
- [ ] 26, 27. Duplicate observation and reference reuse are rejected by REAL Stage 4
- [ ] 28, 29, 30. Valid synthetic observation reaches normal VERIFIED status, updates session, and emits SSE
- [ ] 33. Simulation event is audit logged with full evidence and accessible via history
### apps/payment-api/src/__tests__/developer-test-mode.test.js
- [ ] 1 & 2. Enable Test Mode & verify 10-minute server-side expiration
- [ ] 3. Verify browser refresh does not reset timer (server-authoritative remaining time)
- [ ] 4. Verify server restart does not reset timer (DB persistence)
- [ ] 5 & 6. Inject a valid SMS message & verify passes through real parser and stages
- [ ] 7 & 8. Inject a valid Gmail message & verify passes through real parser
- [ ] 9 & 10. Inject an incorrect amount & verify rejection
- [ ] 11 & 12. Remove CR/CREDIT/CREDITED & verify rejection
- [ ] 13 & 14. Remove Rs/₹ & verify rejection
- [ ] 15 & 16. Remove 12-digit reference & verify rejection
- [ ] 17 & 18. Inject a message received before session creation & verify rejection
- [ ] 19 & 20. Inject a duplicate observation & verify duplicate protection
- [ ] 21 & 22. Inject suspicious message: verify fraud detection occurs and automatic banning is executed immediately
- [ ] 23 & 24. Confirm raw test SMS and Gmail are preserved verbatim
- [ ] 25, 26 & 27. Wait until Test Mode expires: verify Test Mode = OFF & automatic banning is restored
- [ ] 28 & 29. Attempt another synthetic injection after expiration & verify test controls are blocked
- [ ] Audit Log Endpoint returns recorded test injections and metadata
### apps/payment-api/src/__tests__/device-binding-duplicate-session.test.js
- [ ] 1. Device registration (POST /api/v1/devices/register) generates unique deviceId and JWT deviceToken
- [ ] 2. Checkout session binds to the first device that accesses it
- [ ] 3. Same bound device can perform repeated requests without conflict
- [ ] 4. Different device (dev-2) attempting to access session triggers DUPLICATE_SESSION_DETECTED (HTTP 409)
- [ ] 5. Non-bound device (dev-2) is blocked from taking payment actions
- [ ] 6. "End Session & Start New Payment" terminates the old session and creates a fresh session bound to the requesting device
- [ ] 7. If a customer binds to a session, another session for the same customer triggers active session conflict
- [ ] 8. Prompt 64 Regression: Device A creates ACTIVE Session A, Device B attempts access, Device B chooses "End Session & Start New Payment" -> Session A becomes terminal, slot is released, and Device B\
- [ ] 9. Prompt 64 Queue Invariant: When Session A is terminated, existing queued Customer C is promoted to ACTIVE, and new Session B is queued at position 1
- [ ] 10. When duplicate payment session is detected and user starts new payment, admin queue receives info about ongoing session
### apps/payment-api/src/__tests__/fraud-ban-pipeline.test.js
- [ ] 1. "Rs 499" parses to 49900 paise
- [ ] 2. "Rs. 499" parses to 49900 paise
- [ ] 3. "₹499" parses to 49900 paise
- [ ] 4. "INR 499" parses to 49900 paise
- [ ] 5. "Rs 499.00", "Rs. 499.00", "₹499.00", "INR 499.00" all parse to 49900 paise
- [ ] 6. Message with no currency indicator ("credited with 499") fails Stage 3 with MISSING_CURRENCY_INDICATOR. NO ban is applied.
- [ ] 7. Message from unknown personal number (NOT matching customer\
- [ ] 8. Message from personal email (NOT matching customer\
- [ ] 9. Valid bank SMS with wrong amount: Rejected at Stage 4. NO ban applied.
- [ ] 10. Valid bank SMS with missing credit indicator: Rejected at Stage 3. NO ban applied.
- [ ] 11. Malformed text / random string: Rejected at Stage 3. NO ban applied.
- [ ] 12. Message containing "<script>" or "DROP TABLE": Rejected by parser/sanitizer. NO ban applied.
- [ ] 13. Message containing "suspicious", "hack", "test": Processed normally. NO ban applied.
- [ ] 14. Customer with phone +919876543210 has active payment session. Observation arrives from SMS sender "+919876543210". Content contains valid credit format matching session amount.
- [ ] 15. Customer with phone 9876543210 has active payment session. Observation arrives from SMS sender "+91 98765 43210". Result: Auto-ban applied (phone format normalized).
- [ ] 16. Customer with email user@gmail.com has active payment session. Observation arrives from email sender "user@gmail.com". Result: Auto-ban applied.
- [ ] 17. Banned customer attempts to initiate new payment session: Rejected with 403 / BLOCKED status.
- [ ] 18. Banned customer\
- [ ] 19. Banned customer appears in Merchant Panel Banned Users list.
- [ ] 20. Merchant can view complete evidence dossier in Merchant Panel including the EXACT raw message text.
- [ ] 21. Merchant can unban customer with mandatory reason.
- [ ] 22. After unban: customer can initiate payments again.
- [ ] 23. Merchant can manually ban customer with mandatory reason.
- [ ] 24. Manual ban records MANUAL_BAN event in audit log.
- [ ] 25. Auto-ban records FRAUDULENT_PAYMENT_INJECTION event in audit log.
- [ ] 26. When defined fraud scenario occurs: triggers FRAUDULENT_PAYMENT_INJECTION detection, payment is NOT confirmed, customer IS automatically banned, and security event is recorded.
- [ ] 27. Fraud security event has autoBanApplied = true and records verbatim details.
- [ ] 28. Synthetic observations cannot be injected when Test Mode is OFF.
- [ ] 29. Test Mode automatically expires after 10 minutes.
- [ ] 30. When user pushes fake SMS: server detects fraud instantly, auto-bans customer, terminates ongoing session, and checkout state reports BLOCKED under-investigation screen
- [ ] 31. When user pushes fake email: server detects fraud instantly, auto-bans customer, terminates ongoing session, and checkout state reports BLOCKED under-investigation screen
### apps/payment-api/src/__tests__/payment-api.test.js
- [ ] GET /health returns status UP and recovery statistics
- [ ] Complete payment lifecycle: creation, queue, payment-done, observation attribution & promotion
- [ ] Customer registration, login, and decommissioned customer ticket routes
- [ ] Counter manual payment confirmation flow
- [ ] Custom UPI ID persistence & dynamic QR injection
- [ ] Payment method toggles and white-label branding persistence
- [ ] Customer auth binding and trigger-hook dispatch
- [ ] Payment timer does not start until customer logs in or signs up
- [ ] Queue Sync Invariant: When Session A expires, queued Session B is immediately promoted to ACTIVE with armed timer
- [ ] 2-Level Payment Verification Engine: Upgrades verification tier from L1 (SMS) to L2 (Dual: SMS + Gmail)
- [ ] Merchant webhook URL & secret persistence and HMAC-SHA256 signed event delivery
- [ ] Auto-detection in ACTIVE state without Payment Done click: immediate SUCCESS, L1 to L2 upgrade, exactly 1 webhook
### apps/payment-api/src/__tests__/selling-modes-and-cod.test.js
- [ ] 1. Admin VPA Configuration: Clean VPA + Payee Name without merchant/personal distinction
- [ ] 2. Online Selling Mode Configuration & Method Invariants
- [ ] 3. COD Pre-Purchase Confirmation, Slot Release, & Developer API Endpoints
- [ ] 4. Offline Selling Mode: Cash and Card (renamed), COD completely removed
### apps/payment-api/src/__tests__/sms-gmail-verification-levels.test.js
- [ ] POST /api/v1/admin/queue/clear expires all waiting UPI queue sessions
- [ ] Detects active session conflict when customer already has an active session
- [ ] Android SMS Gateway pairs via QR, sends HMAC-signed bank SMS and completes payment
- [ ] Gmail credentials can be saved and connection tested via Admin API
- [ ] Downloads standalone Android APK binary from Payment API
### apps/payment-api/src/__tests__/upi-server-health-and-pc-ui.test.js
- [ ] GET /api/v1/checkout/server-health returns valid health object
- [ ] Server health indicator detects offline pollers and issues warning
- [ ] Server health switches to WORKING when both Gmail OAuth and active SMS device are connected
- [ ] Dev settings: False narrative switch enables 10-minute override and forces WORKING status
- [ ] Dev settings: False narrative automatically expires after 10 minutes and reverts to live status
- [ ] Dev settings: Disabling false narrative explicitly immediately reverts to live status
### apps/payment-api/src/__tests__/whatsapp-berry-auth.test.js
- [ ] WhatsApp Berry Protocol OTP Customer Auth & Merchant Configuration Suite
### apps/payment-api/src/__tests__/whitelabel-branding.test.js
- [ ] 1. GET /api/v1/admin/merchant-settings returns baseline white-label branding
- [ ] 2. POST /api/v1/admin/merchant-settings updates and persists extended white-label settings
- [ ] 3. Checkout state endpoint propagates updated branding to customer checkout
### apps/payment-help/src/__tests__/payment-help.test.js
- [ ] GET /health returns UP
- [ ] GET / and GET /support and GET /help display ONLY "Feature not available"
- [ ] GET /api/customer/tickets is decommissioned and returns 404
### apps/sms-observer/src/__tests__/sms-observer.test.js
- [ ] correctly parses credit SMS with amount, UTR, and sender VPA
- [ ] ignores debit SMS alerts
- [ ] GET /health returns UP
- [ ] End-to-End: processing bank SMS confirms active payment session in Payment API
### packages/common/src/__tests__/common.test.js
- [x] converts valid rupee strings and numbers to integer paise — `tests/Unit/Domain/ValueObjectsTest.php`
- [x] rejects negative, zero, and malformed currency inputs — `tests/Unit/Domain/ValueObjectsTest.php`
- [x] formats paise into standard decimal string and INR symbol — `tests/Unit/Domain/ValueObjectsTest.php`
- [x] encrypts and decrypts with AES-256-GCM successfully — replaced by Laravel authenticated encrypted casts (AES-256-CBC + MAC), `tests/Feature/Database/ModelsTest.php`; legacy ciphertext is re-encrypted on import.
- [x] fails decryption if ciphertext or auth tag is tampered — `tests/Feature/Database/ModelsTest.php`
- [x] generates and verifies HMAC-SHA256 signatures with timing safety — `tests/Feature/Database/ModelsTest.php`
- [x] hashes and verifies passwords securely — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] validates UPI VPAs correctly — `tests/Unit/Domain/ValueObjectsTest.php`
- [x] builds canonical upi://pay URI dynamically with server amount — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] strictly excludes cu, tr and all extra parameters even when currency option is passed — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] builds valid Android intent URI containing only canonical UPI parameters — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] validates 30 official UPI application provider registry — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] generates consistent fingerprints with bank reference numbers — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] generates cluster fingerprints within same 60-second window when no reference — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
### packages/core/src/__tests__/core.test.js
- [x] permits valid transitions and denies illegal transitions — `tests/Feature/Payments/QueueTransitionsTest.php` (Phase 2 transitions/queue)
- [x] maps internal terminal states to public contract outcomes — `tests/Feature/Payments/QueueTransitionsTest.php` (Phase 2 transitions/queue)
- [x] extracts monetary amounts from various notification formats — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] normalizes explicit amount in paise directly — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] intelligently extracts amounts without standard currency symbols e.g. "20.00 received" — `tests/Unit/PaymentPrimitivesTest.php` (Phase 2 primitives)
- [x] attributes matching observation to active processing session — `tests/Feature/Verification/VerificationTest.php`
- [x] deduplicates multi-channel observations (SMS + Gmail) — `tests/Feature/Verification/VerificationTest.php`
- [x] CRITICAL INVARIANT: Late observation does not attach to a new queued customer — `tests/Feature/Verification/VerificationTest.php`
### packages/core/src/__tests__/queue-timing.test.js
- [x] Queue Engine correctly assigns active slot and queued positions with wait estimates — `tests/Feature/Payments/QueueTransitionsTest.php` (Phase 2 transitions/queue)
- [x] Timing Engine: 5-minute active window expires and auto-promotes next in queue — `tests/Feature/Payments/QueueTransitionsTest.php`
- [x] Timing Engine: Payment Done starts 30-sec processing window, fails if no observation — `tests/Feature/Payments/QueueTransitionsTest.php`
- [x] Recovery Engine: crash recovery cleans up overdue sessions and promotes queued — `tests/Feature/Payments/QueueTransitionsTest.php`
### packages/core/src/__tests__/webhook-engine.test.js
- [x] successfully dispatches HMAC-SHA256 signed webhook on payment event — `tests/Feature/Webhooks/WebhookTest.php` (Phase 2 webhooks)
- [x] records failure and supports retry on merchant HTTP 500 error — `tests/Feature/Webhooks/WebhookTest.php` (Phase 2 webhooks)
### packages/db/src/__tests__/auth-matrix.test.js
- [ ] Full 53-Point Customer Identity, Authentication & Payment Invariant Matrix
### packages/db/src/__tests__/customer-reset.test.js
- [x] Production & Development Database Safe Customer Reset and Zero Residue Invariant — safe disposable-test equivalent in `tests/Feature/Database/ModelsTest.php`; no production reset command or production file access.
### packages/db/src/__tests__/db.test.js
- [x] migrations seed default merchant and active UPI receiving account — `tests/Feature/Database/LegacyDatabaseParityTest.php` (Phase 1)
- [x] customer registration and password verification — `tests/Feature/Database/ModelsTest.php`
- [x] enforces ONE active slot at one time and queues subsequent sessions — `tests/Feature/Payments/QueueTransitionsTest.php`
- [x] transitions to CANCELLED and immediately releases slot — `tests/Feature/Payments/QueueTransitionsTest.php`
- [x] records observations and retrieves by fingerprint for deduplication — `tests/Feature/Database/LegacyDatabaseParityTest.php` (Phase 1)
- [x] creates tickets, links payments, and records bidirectional chat — `tests/Feature/Database/LegacyDatabaseParityTest.php` (Phase 1)
- [x] records and queries audit trail — `tests/Feature/Database/LegacyDatabaseParityTest.php` (Phase 1)
### packages/db/src/__tests__/sqlite_verify.test.js
- [ ] native node:sqlite operates in memory
### packages/merchant-sdk/src/__tests__/merchant-sdk.test.js
- [x] creates a payment session using SDK client
- [x] queries payment status and details
- [x] successfully verifies authentic HMAC-SHA256 webhook payload
- [x] rejects tampered webhook payload or incorrect secret
- [x] rejects malformed signature header
### packages/merchant-sdk/src/__tests__/paykr-universal-integration.test.js
- [x] SDK exports both PaykrCheckout and Paykr aliases — Phase 9 checkout feature/browser checks
- [x] Paykr.pay creates payment session and resolves checkoutUrl on backend — Phase 9 checkout feature/browser checks
- [ ] Button attribute extractor correctly parses customer and metadata attributes
### scripts/chaos-concurrency.test.js
- [x] CHAOS 1: 10 simultaneous sessions for identical amounts (₹200) acquire slot deterministically — `tests/Feature/Payments/MySqlQueueConcurrencyTest.php`
- [x] CHAOS 2: Multi-tab simultaneous Payment Done clicks are strictly idempotent — `tests/Feature/Payments/MySqlQueueConcurrencyTest.php`
- [x] CHAOS 3: Dual-channel flood (Gmail + SMS) guarantees exactly 1 payment attribution — `tests/Feature/Payments/MySqlQueueConcurrencyTest.php`
- [x] CHAOS 4: Promotion cascade: completed session immediately unblocks waiting queue in order — `tests/Feature/Payments/QueueTransitionsTest.php`
- [x] CHAOS 5: Late observation does not hijack currently active customer session — `tests/Feature/Verification/VerificationTest.php`
### scripts/production-infrastructure.test.js
- [ ] 1, 2, 3: Generates deterministic hostnames for domain and prefix
- [ ] 4: Checkout maps to port 7000
- [ ] 5: API maps to port 6100
- [ ] 6: Admin maps to port 6001
- [ ] 7: Gmail maps to port 6003
- [ ] 8: Demo Store maps to port 5000 and is clearly optional
- [ ] 9, 10: Control Center (8090) and Legacy SMS Observer (6002) are NOT exposed in Cloudflare mappings
- [ ] 11, 12: Public URLs are consistent across runtime resolver without competing matrices
- [ ] 13, 14: resolveRuntimeUrls throws error in production if domain is missing; does not fall back to localhost
- [ ] 15: Stored production settings survive restart without auto-reset to localhost
- [ ] 16, 17: Localhost mode is only active when explicitly selected
- [ ] 18, 19, 22: QR contains structured pairing payload with version, token, and session ID
- [ ] 20: Production pairing URL follows configured production domain
- [ ] 21: Localhost pairing URL follows localhost:6100
- [ ] 23, 24, 25: Validates payload and rejects expired or malformed payloads
- [ ] 29, 30, 31: Observer forwards non-bank SMS; server does not reject with 400
- [ ] 35: Production Gmail redirect URI derived as pay-gmail.example.com/oauth/callback
- [ ] 36: Localhost Gmail redirect URI derived as localhost:6003/oauth/callback
- [ ] 37, 38: Gmail offline refresh token persists in merchant configuration across restarts
- [ ] 41, 42, 44: Gmail observer raw email payload preserves fields and transports without local domain filtering
- [ ] 45, 46, 47: Browser automator does not auto-launch Chrome when ENABLE_LEGACY_CHROME_OBSERVERS is not set

## Dropped / replaced

See migration specification §18; individual route/test dispositions remain to be recorded in the relevant phase.

## Phase 1 verification

- [x] Domain tables, UUID models, factories, typed enums, encrypted secrets and JSON casts.
- [x] Fail-closed tenant scopes and write guards; explicit administrative context with automatic restoration.
- [x] Database constraint for one active receiving account per merchant; composite foreign keys prevent cross-merchant references.
- [x] MySQL 8 migrations, rollback/reapply, and demo receiving-account seeding.
- [x] Read-only SQLite import: 13 source tables, all row counts match; source SHA-256 unchanged.
- [x] 79 tests / 761 assertions pass against MySQL 8.4 and Redis 8; 99.2% overall / 98.83% domain coverage.
- [x] Pint and Larastan (level 6 application / level 8 domain) pass.

Imported copy: `paykr_import_check`. Development database: `paykr` (seeded demo; legacy data not merged).
The four checked legacy tests above cover database persistence only; domain workflow tests remain for Phase 2.


## Phase 2 transition and queue verification

- [x] Account-before-session row locks, transactional audit records, after-commit typed events.
- [x] Closed sessions reject all transitions, including repeated terminal outcomes and counter declines.
- [x] PROCESSING retains its UPI slot; counter/COD payments do not take another payment's slot.
- [x] FIFO promotion, queue reindexing, deadline expiry, stale-pointer repair and wait estimates.
- [x] Cross-tenant lookups and mismatched account/actor requests fail closed.
- [x] Real MySQL test: 50 independent customer processes produce one ACTIVE and 49 QUEUED payments.
- [x] Ten concurrent cancellations produce one terminal event, one audit entry and one promotion.
- [x] Terminal webhook creation/delivery parity — `tests/Feature/Webhooks/WebhookTest.php`; MySQL process race also checks one persisted delivery and one fake HTTP request across duplicate jobs.
- [ ] Scheduled timing/recovery worker and realtime client broadcasts (later phases).

Tests: `tests/Feature/Payments/QueueTransitionsTest.php` and
`tests/Feature/Payments/MySqlQueueConcurrencyTest.php`. The concurrency case runs automatically
with `DB_CONNECTION=mysql` and a database ending in `_test`; SQLite skips that case explicitly.


## Phase 2 webhook verification

- [x] Persist the serialized webhook in the terminal payment transaction; rollback leaves neither outcome nor delivery.
- [x] Exact payload key order, millisecond completion timestamp, integer paise, and raw-body HMAC headers.
- [x] Retry the original body, endpoint and delivery UUID; total attempt limit and persisted backoff.
- [x] Ten duplicate MySQL delivery jobs yield one successful HTTP attempt (fake receiver).
- [x] Explicit merchant context in jobs; cross-tenant delivery IDs send nothing.
- [x] Production HTTPS/public-IP checks, validated DNS pinning, redirects/proxies disabled.
- [x] Redis dispatch failure retains the due delivery; recovery command re-enqueues it.
- [x] Round queue availability up to whole seconds so millisecond retry deadlines cannot consume a job early.
- [ ] Merchant-panel resend/test actions and periodic recovery scheduling (later phases).


## Phase 2 verification and automatic fraud handling

- [x] Six-stage pipeline; rejected sender/parser/amount/time/security checks never reach attribution.
- [x] Same-account attribution, per-channel deduplication, persisted L1 grace and L2 upgrades.
- [x] Closed orders remain closed; late evidence creates one customer support ticket.
- [x] Grace recovery after queue failures and cancellation, with explicit merchant context.
- [x] Raw fraud evidence, automatic global customer ban and terminal outcomes for live sessions.
- [x] Ban suppression requires persisted, unexpired merchant test mode and a test session.
- [x] MySQL observer race: one payment success and webhook across simultaneous SMS/Gmail submissions.
- [x] Manual ban/unban domain operations, admin-only evidence queries, device access enforcement and UPI health.
- [ ] Evidence UI and HTTP integration (later phases).
- [ ] Periodic recovery scheduling and realtime client broadcasts (later phases).

Coverage: `tests/Feature/Verification/VerificationTest.php` and
`tests/Feature/Payments/MySqlQueueConcurrencyTest.php`. These domain checks do not yet establish
HTTP route or simulator UI parity; those remain unchecked above until their integration phase.


## Phase 2 completion

- [x] Admin-validated manual bans/unbans with actual actor IDs, audit and security history.
- [x] Global bans close live orders through transitions/webhooks; unbanning does not reopen them.
- [x] Shared transitions reject activation/processing/success for banned or merchant-blocked customers.
- [x] Ban evidence shape, verbatim raw text, successful spending totals and searchable banned list.
- [x] Live bound devices require matching token hashes; imported public IDs, revocation and tenant isolation tested.
- [x] New placeholder bindings issue a random token once; terminal status remains readable.
- [x] UPI health isolates merchants and checks fresh Android/Gmail activity; stale or wrong-merchant sidecar snapshots cannot mark SMS healthy.
- [x] Domain timing/recovery and ten concurrent Payment Done calls preserve one processing deadline.

`tests/Feature/Security/PhaseTwoServicesTest.php` covers the new domain services. Core/common/db
and chaos cases specified for Phase 2 are mapped above. Node's production-file reset test is replaced
with a disposable relational cleanup test. Laravel's authenticated encrypted casts replace Node's
ciphertext format; no live encryption settings were changed. The 53-point HTTP identity matrix,
clock process/jobs/rescheduling, private realtime broadcasts, sidecar adapters and Filament screens
remain assigned to their later phases. Phase 2 does not expose new public routes.


## Phase 3 clock, jobs and recovery

- [x] Redis singleton lease, renewal, one-second ticks and graceful signal handling.
- [x] Real MySQL/Redis process test: duplicate clock denied; SIGKILL followed by lease expiry and startup recovery closes the overdue payment once.
- [x] Real-time deadline test: ACTIVE expiry and PROCESSING failure commit within one second after their deadlines.
- [x] Delayed expiry/failure jobs ignore early, stale, terminal and cross-tenant requests.
- [x] Startup requeues future payment/grace deadlines; missing jobs do not lose persisted state.
- [x] Recover missed verification and expiry in persisted deadline order.
- [x] Pipeline-approved pending observation retries preserve observation identity and account isolation.
- [x] Gmail refresh/list/fetch, per-merchant receipts, overlap protection, throttled burst and idle jobs; HTTP faked in tests.
- [x] Authenticated merchant-specific Messages sweeps run off the clock, only when enabled.
- [x] COD independent five-minute deadline; counter payments untimed; switching back to UPI joins the queue.
- [x] Priority Horizon queues, heartbeat/recovery health fields, admin System page, scheduled recovery and receipt pruning.
- [x] Supervisor/systemd process examples and deployment notes.

Integration checks live in `tests/Feature/Clock`. Existing queue-timing and MySQL concurrency tests
continue to run. Gmail OAuth connection flows, Node sidecar packages, checkout routes and realtime
client broadcasts remain in their assigned later phases. No live Gmail or merchant HTTP was used.


## Phase 4 API contract equivalents

Routes 59–82 and 88–95 are implemented. The route checks indicate migrated endpoint behavior;
legacy browser/admin UI assertions remain in their own phases, and security FIXes intentionally
replace insecure legacy expectations.

| Legacy suite / behavior | Laravel acceptance coverage |
| --- | --- |
| payment-api: create/read, merchant scope, device conflicts, duplicate orders | `tests/Feature/PaymentApiTest.php` |
| payment-api: simultaneous duplicate submissions | `tests/Feature/Payments/MySqlQueueConcurrencyTest.php` (ten independent workers) |
| auth-matrix: API keys/origins, customer session, device proof, privileged simulation | `PaymentApiTest.php`, `CustomerApiTest.php`, `DeviceApiTest.php`, `CheckoutApiTest.php` |
| whatsapp-berry-auth: OTP signup/login, dispatch failure, attempts and expiry | `tests/Feature/CustomerApiTest.php` (fake sender; production OTP suppression) |
| device-binding-duplicate-session: token proof, customer binding, same-device conflict, cross-device restart | `tests/Feature/CheckoutApiTest.php`; existing `Security/PhaseTwoServicesTest.php` |
| selling-modes-and-cod / counter-payments API: COD finality, offline cash, parallel deadlines | `tests/Feature/CheckoutApiTest.php`; existing `Payments/QueueTransitionsTest.php` and clock tests |
| upi-server-health-and-pc-ui API: scoped health | `tests/Feature/CheckoutApiTest.php`; existing `Security/PhaseTwoServicesTest.php` (observer freshness) |
| whitelabel-branding API: public allowlist, nested secret removal | `tests/Feature/CheckoutApiTest.php`; `/state` contains no Gmail/test-mode keys |
| admin-auth-and-final-states equivalents: staff/test-mode simulator restrictions and immutable terminal outcomes | `tests/Feature/CheckoutApiTest.php`; existing foundation and payment transition tests |

Compatibility notes: a known device public key alone never yields its hashed token; fresh placeholder
creation returns a token once. Cross-device restart needs the owning customer session plus the new
device token. Terminal public state omits customer details. SSE paths return 410; Reverb channel
authorization/transport and browser UI tests remain assigned to the later realtime/checkout phases.


## Phase 5 observer equivalents

| Legacy suite | Laravel coverage |
| --- | --- |
| android-observer | `ObserversApiTest.php`: original driver replay, QR expiry/version, encrypted signing keys, unsigned/malformed/stale/replayed rejection, heartbeat and original APK download |
| gmail-observer | `Clock/GmailJobsTest.php` and `GmailOAuthTest.php`: refresh, poll, full MIME extraction, per-merchant deduplication, profile test, revoke and disconnect |
| oauth-view | `GmailOAuthTest.php`: safe result pages, user-bound/single-use/expired/tampered state, consent denial and provider failures without token leakage |
| sms-observer | `ObserversApiTest.php`: loopback/internal-token/HMAC checks, merchant/account isolation, raw sender and text, non-bank acknowledgment |
| sms-gmail-verification-levels | Existing `Verification/VerificationTest.php` plus SMS, Gmail and sidecar HTTP/job intake tests through the actual pipeline |
| fraud-ban-pipeline | `ObserversApiTest.php` signed customer-origin message and existing verification/security evidence tests |
| concurrent token/replay consumption | `Payments/MySqlQueueConcurrencyTest.php`: ten independent pairing contenders, then ten identical signed-request contenders |

Rows 96–104 and 108–109 are migrated. Pairing QR issuance is available through the authenticated
merchant API; the old admin QR/device-list/unpair UI routes remain assigned to Filament in Phase 8.
The old Android fixture that finalized an alert without a raw 12-digit reference is intentionally
replaced by pipeline rejection coverage. Live phone/Google consent acceptance is separate from
these deterministic request replays and fake-provider integration tests.

## Phase 6 sidecar equivalents

- [x] WhatsApp authenticated loopback status/connect/pairing/disconnect/send/send-otp service.
- [x] Branded OTP template, saved-session reconnect, explicit simulation and status callback.
- [x] Merchant-isolated Google Messages profiles, pairing monitor, background extraction and signed raw observations.
- [x] No fabricated sender or amount-only fallback; unknown sender/time fails closed.
- [x] Feature flags, bounded HTTP timeouts, circuit cooldown and RED/YELLOW degraded UPI health.
- [x] Node service tests and Laravel feature/architecture/coverage checks.
- [x] Live WhatsApp phone receipt: sender linked by code, test OTP sent through Laravel, and recipient confirmed receipt.
- [ ] Live Google Messages extraction with a paired Android phone.

The existing admin/merchant WhatsApp and Google Messages panel route replacements remain
Phase 8. Service endpoints and Laravel clients are implemented; panel checkboxes above are
not marked complete merely because their sidecars now exist.

### Phase 6 acceptance update: user-selected SMS transport

- [x] Reviewed Android observer replaces Google Messages for this installation's live SMS capture, as requested by the user.
- [x] Real phone pairing, heartbeat, bank-credit extraction and payment attribution verified.
- [x] Offline diagnostic SMS retried after reconnect; user confirmed an empty phone queue.
- [x] Background finalization, clock recovery and scheduler recovery checked in isolated processes.
- [x] Delayed-credit retention and ambiguous-order matching regression checks; merchant review approval with explicit closed-order reopening.

Google Messages implementation is retained but its live browser acceptance checkbox remains open and the feature disabled. Phase 6 can close with that transport replaced; this does not claim Google Messages was tested successfully. Merchant Credit Reviews is a limited early Phase 8 feature, not completion of Phase 8. Phase 7 real-time delivery is next.

## Phase 7 real-time

- [x] Private payment channels require a bound active device token or a bound active customer session.
- [x] Private merchant channels require active tenant membership; cross-tenant and public/presence channel attempts fail closed.
- [x] `PaymentStatusChanged`, `PaymentAccountBlocked`, `LivePaymentUpdated`, `CounterPaymentRequested`, and `QueuePromoted` map from after-commit domain events.
- [x] Queue-position changes reach each affected payment; payloads omit models, raw observations and credentials.
- [x] Legacy checkout `/stream` and `/events` retain HTTP 410 with the Reverb hint.
- [x] Browser checks with actual Reverb and Redis: live transition, blocked socket with five-second polling, reconnect refresh.
- [x] Minimal checkout status shell and merchant Livewire refresh integration; full checkout design remains Phase 9 and full panels remain Phase 8.

## Phase 8 panel verification

All Filament route replacements above are implemented as authenticated panel pages/actions;
legacy `/api/v1/admin/*` paths are not reintroduced. `tests/Feature/Filament/` covers the
role matrix, tenant-scoped tables and forged actions, counter replay, queue isolation,
receiving-account slot guards, branding/method settings, observer pairing, developer tools,
last-owner protection, platform controls and audit attribution. `PanelAuditTest.php` verifies
that support impersonation retains the actual administrator ID/IP and ends without logging
out the administrator. Existing Phase 2 tests cover mandatory administrator TOTP.

Team invitations expose a one-time password-setup link to the owner for sharing. Existing
users are attached without resetting their passwords. No email is sent automatically.
Platform APK uploads validate package structure and publish a version/hash; signing the
release remains the Android build step. Google Messages remains disabled; Android SMS and
Gmail use the existing ingestion services. Full customer checkout and the SDK remain Phase 9.

`tests/Support/check-panels.php` + `tests/Browser/panels.mjs` passed the real-browser
merchant/cashier/admin workflows, including a TOTP challenge and independent audit-ID/IP
verification. Final phase checks: 276 PHP tests passed (six MySQL-only skips), 93.4% overall
coverage, 95.17% domain coverage; both PHPStan configurations, Pint and Vite build pass.

## Phase 9 checkout and browser SDK verification

- [x] React checkout with responsive desktop/mobile layouts, payment ID, protected QR, provider icons, branding, order details and help/cancel sheets.
- [x] OTP sign-up/sign-in, persisted account support and secure device registration; authenticated duplicate-device restart.
- [x] UPI timers, processing, queue promotion, COD/counter and terminal/fraud states.
- [x] SDK aliases and cross-origin payment creation, minification/SRI and merchant integration snippet.
- [x] Exact postMessage origin/source checks and merchant-origin frame restrictions.
- [x] Synthetic signed Android credit to checkout success and HMAC-verified merchant webhook on desktop/mobile.
- [x] Cancellation during verification grace and late-credit review without automatically reopening closed orders.

Evidence: `tests/Feature/CheckoutPageTest.php`, `tests/Browser/checkout.mjs` and
`tests/Support/check-checkout.php`. The dynamic QR uses the existing server QR service;
the old fixed 29×29 JavaScript matrix implementation is intentionally replaced, not
claimed as a byte-for-byte port. The old separate portal health endpoint is replaced
by Laravel's application health endpoint. Legacy payment-help endpoints are replaced
by the checkout help sheet and existing support/review services. The Node server-side
merchant SDK checklist remains separate from this browser SDK phase.

React realtime regression passed with real Redis/Reverb: live updates, measured five-second
blocked-WebSocket polling and offline reconnection. Coverage: 279 PHP tests, six MySQL-only
skips, 93.4% overall and 95.15% domain coverage.

## Phase 10 — security requirement evidence

Review: [SECURITY-REVIEW.md](SECURITY-REVIEW.md). Paths below are relative to the project root.

| Spec §16 | Implementation / verification |
| --- | --- |
| 1. Panels, TOTP, login limits, cookies, CSRF | [PanelAccessTest](../tests/Feature/Filament/PanelAccessTest.php), [ProductionSecretsTest](../tests/Feature/ProductionSecretsTest.php), [WebBoundaryTest](../tests/Feature/Security/WebBoundaryTest.php); both panel providers retain CSRF middleware |
| 2. Hashed keys, origins, 60/min/key/IP | [PaymentApiTest](../tests/Feature/PaymentApiTest.php), merchant-api limiter in AppServiceProvider |
| 3. Encrypted provider/signing secrets | [ModelsTest](../tests/Feature/Database/ModelsTest.php), [GmailOAuthTest](../tests/Feature/GmailOAuthTest.php), [ObserversApiTest](../tests/Feature/ObserversApiTest.php) |
| 4. Hashed customer/device tokens and OTP limits | [CustomerApiTest](../tests/Feature/CustomerApiTest.php), [DeviceApiTest](../tests/Feature/DeviceApiTest.php) |
| 5. Admin, simulation and observer authorization | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php), [ObserversApiTest](../tests/Feature/ObserversApiTest.php), [PanelAccessTest](../tests/Feature/Filament/PanelAccessTest.php) |
| 6. Final pipeline rejection, account scoping | [VerificationTest](../tests/Feature/Verification/VerificationTest.php) |
| 7. Public allowlists | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php), [CheckoutPageTest](../tests/Feature/CheckoutPageTest.php) |
| 8. Escaping and CSP | [CheckoutPageTest](../tests/Feature/CheckoutPageTest.php), [browser acceptance](../tests/Browser/checkout.mjs) |
| 9. Webhook SSRF | [WebhookTest](../tests/Feature/Webhooks/WebhookTest.php): private/mixed DNS, HTTPS, pinning, redirects and proxies |
| 10. No user shell execution | [ArchitectureTest](../tests/Arch/ArchitectureTest.php); tunnel feature removed |
| 11. Actor/IP audit | [PanelAuditTest](../tests/Feature/Filament/PanelAuditTest.php), [ManagementServicesTest](../tests/Feature/Filament/ManagementServicesTest.php), [CreditReviewsTest](../tests/Feature/Filament/CreditReviewsTest.php) |
| 12. Production secrets/configuration guards | [ProductionSecretsTest](../tests/Feature/ProductionSecretsTest.php), [LegacyDatabaseParityTest](../tests/Feature/Database/LegacyDatabaseParityTest.php) |
| 13. Endpoint/OTP rate limits | routes/api.php, AppServiceProvider, [CustomerApiTest](../tests/Feature/CustomerApiTest.php), [PaymentApiTest](../tests/Feature/PaymentApiTest.php) |

| Spec §19.2 regression | Proof |
| --- | --- |
| Pipeline rejection fall-through; gateway-wide attribution | [VerificationTest](../tests/Feature/Verification/VerificationTest.php) rejects and never searches another account |
| Bare customer binding; unauthenticated cancel; X-Internal-Render bypass | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php) requires device proof and authenticated customer |
| Branding/metadata credential leaks | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php), [CheckoutPageTest](../tests/Feature/CheckoutPageTest.php) |
| Customer enumeration; weak OTP randomness/attempts | [CustomerApiTest](../tests/Feature/CustomerApiTest.php); CustomerAuthService uses random_int and hashed OTPs, masked identity and limits |
| Gmail configuration copied across merchants | [GmailOAuthTest](../tests/Feature/GmailOAuthTest.php), [TenancyTest](../tests/Feature/Database/TenancyTest.php) |
| Cross-merchant queue clearing | [ManagementServicesTest](../tests/Feature/Filament/ManagementServicesTest.php) |
| Missing daemon/stale expiry webhooks | [ClockTest](../tests/Feature/Clock/ClockTest.php), [WebhookTest](../tests/Feature/Webhooks/WebhookTest.php) |
| Counter decline on closed sessions | [QueueTransitionsTest](../tests/Feature/Payments/QueueTransitionsTest.php), [ManagementServicesTest](../tests/Feature/Filament/ManagementServicesTest.php) |
| Expire without deadline | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php) |
| Fake APK stub | [ObserversApiTest](../tests/Feature/ObserversApiTest.php) verifies actual bytes and missing-artifact error |
| QR fallback UPI/amount | [CheckoutApiTest](../tests/Feature/CheckoutApiTest.php), QrCodeService uses active session account; no default VPA |
| Unescaped branding; arbitrary postMessage origin | [CheckoutPageTest](../tests/Feature/CheckoutPageTest.php), [checkout browser check](../tests/Browser/checkout.mjs) |
| Google Messages synthetic sender/amount fallback | services/tests; [ObserversApiTest](../tests/Feature/ObserversApiTest.php) rejects old amount-only shortcuts |
| Gmail healthy merely from token | [PhaseTwoServicesTest](../tests/Feature/Security/PhaseTwoServicesTest.php) requires fresh successful poll |
| Tunnel shell execution | [ArchitectureTest](../tests/Arch/ArchitectureTest.php), removed configuration surface |

Load gates: [MySqlQueueConcurrencyTest](../tests/Feature/Payments/MySqlQueueConcurrencyTest.php)
(50 simultaneous customers, real row locks), [PaymentApiTest](../tests/Feature/PaymentApiTest.php)
(200 creations across 20 merchants in under one minute). This replaces the legacy Node
production infrastructure topology assertions as explained in the security review.

## Phase 11 — owner-approved local rehearsal

Node never served production, so import, freeze/DNS switch and source reconciliation are
not applicable. The owner explicitly selected local rehearsal instead of hosted staging.
See the dated specification scope decision and [CUTOVER.md](CUTOVER.md) for future launch gates.

- [x] Merchant SDK 2.0.0 retains createPayment/getPayment/verifyWebhook and browser named aliases; server authentication uses secret keys, legacy customer.fullName is normalized and idempotency is preserved.
- [x] SDK tarball produced locally; no registry publication claimed.
- [x] Standalone demo: home/wallet/outcome pages and short aliases, escaped parameters, balance/history, server-created checkout and authenticated verification.
- [x] HMAC-signed SUCCESS credits only a matching stored payment/order/amount/currency, once; rejects forged/malformed/tampered signatures and unknown orders.
- [x] Client coin amounts cannot change fulfilment. Pending payments cannot credit. Stored credits survive restart.
- [x] Real Laravel create/query/idempotency and browser store-button → checkout navigation pass in disposable fixtures.
- [x] Node/PHP/Python/Go raw-body HMAC smoke tests pass for valid and invalid signatures.
- [x] First-launch configuration, encrypted backups/restore rehearsal, provider pairing and payment-safe rollback documented.

Evidence: `tests/Support/check-merchant-demo.php`, `tests/Integration/merchant-demo.mjs`,
`tests/Integration/wallet.test.mjs`, `tests/Integration/webhook-verifiers.mjs`, and
`packages/merchant-sdk/test/client.test.mjs`. CI runs these alongside the checkout matrix.
Legacy tests that credit arbitrary popup data or signed unknown orders are intentionally
replaced by rejection tests. Decorative screenshot assertions and Node service reverse
proxies are superseded by the separate demo and canonical Laravel URLs. This is a local
integration acceptance result, not a hosted deployment or public package publication.
