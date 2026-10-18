# Appendix A — Machine-extracted inventory of the current Paykr (Node.js) codebase

Generated from the repository source on 2026-10-17. Every item here must be either
implemented in the Laravel build or explicitly listed as dropped in `01-MIGRATION-SPEC.md` § "Dropped / replaced".

## A1. Payment API routes (`apps/payment-api`, port 6100)

| # | Method | Path | Source |
|---|---|---|---|
| 1 | GET | `/health` | apps/payment-api/src/index.js:415 |
| 2 | GET | `/api/v1/health` | apps/payment-api/src/index.js:416 |
| 3 | GET | `/api/v1/admin/queue` | apps/payment-api/src/routes/admin.routes.js:109 |
| 4 | GET | `/api/v1/admin/observers` | apps/payment-api/src/routes/admin.routes.js:163 |
| 5 | GET | `/api/v1/admin/payments` | apps/payment-api/src/routes/admin.routes.js:221 |
| 6 | GET | `/api/v1/admin/tickets` | apps/payment-api/src/routes/admin.routes.js:244 |
| 7 | POST | `/api/v1/admin/tickets/:id/resolve` | apps/payment-api/src/routes/admin.routes.js:255 |
| 8 | POST | `/api/v1/admin/counter-confirm` | apps/payment-api/src/routes/admin.routes.js:265 |
| 9 | POST | `/api/v1/admin/counter-decline` | apps/payment-api/src/routes/admin.routes.js:332 |
| 10 | GET | `/api/v1/admin/upi-settings` | apps/payment-api/src/routes/admin.routes.js:395 |
| 11 | GET | `/api/v1/admin/upi-accounts` | apps/payment-api/src/routes/admin.routes.js:423 |
| 12 | POST | `/api/v1/admin/upi-accounts` | apps/payment-api/src/routes/admin.routes.js:448 |
| 13 | POST | `/api/v1/admin/upi-accounts/:id/activate` | apps/payment-api/src/routes/admin.routes.js:499 |
| 14 | POST | `/api/v1/admin/upi-accounts/:id/toggle` | apps/payment-api/src/routes/admin.routes.js:528 |
| 15 | DELETE | `/api/v1/admin/upi-accounts/:id` | apps/payment-api/src/routes/admin.routes.js:558 |
| 16 | POST | `/api/v1/admin/upi-settings` | apps/payment-api/src/routes/admin.routes.js:582 |
| 17 | GET | `/api/v1/admin/all-settings` | apps/payment-api/src/routes/admin.routes.js:636 |
| 18 | POST | `/api/v1/admin/all-settings` | apps/payment-api/src/routes/admin.routes.js:690 |
| 19 | GET | `/api/v1/admin/merchant-settings` | apps/payment-api/src/routes/admin.routes.js:812 |
| 20 | POST | `/api/v1/admin/merchant-settings` | apps/payment-api/src/routes/admin.routes.js:899 |
| 21 | GET | `/api/v1/admin/cod-orders` | apps/payment-api/src/routes/admin.routes.js:1055 |
| 22 | GET | `/api/v1/admin/stream` | apps/payment-api/src/routes/admin.routes.js:1103 |
| 23 | GET | `/api/v1/admin/whatsapp/status` | apps/payment-api/src/routes/admin.routes.js:1110 |
| 24 | GET | `/api/v1/admin/whatsapp/qr` | apps/payment-api/src/routes/admin.routes.js:1153 |
| 25 | POST | `/api/v1/admin/whatsapp/reconnect` | apps/payment-api/src/routes/admin.routes.js:1184 |
| 26 | POST | `/api/v1/admin/whatsapp/pairing-code` | apps/payment-api/src/routes/admin.routes.js:1230 |
| 27 | POST | `/api/v1/admin/whatsapp/disconnect` | apps/payment-api/src/routes/admin.routes.js:1260 |
| 28 | POST | `/api/v1/admin/whatsapp/test-message` | apps/payment-api/src/routes/admin.routes.js:1276 |
| 29 | POST | `/api/v1/admin/queue/clear` | apps/payment-api/src/routes/admin.routes.js:1301 |
| 30 | GET | `/api/v1/admin/counter-pending` | apps/payment-api/src/routes/admin.routes.js:1348 |
| 31 | GET | `/api/v1/admin/tunnel/status` | apps/payment-api/src/routes/admin.routes.js:1382 |
| 32 | POST | `/api/v1/admin/tunnel/start` | apps/payment-api/src/routes/admin.routes.js:1387 |
| 33 | POST | `/api/v1/admin/tunnel/stop` | apps/payment-api/src/routes/admin.routes.js:1420 |
| 34 | GET | `/api/v1/admin/gmail/credentials` | apps/payment-api/src/routes/admin.routes.js:1426 |
| 35 | GET | `/api/v1/admin/gmail/oauth/start` | apps/payment-api/src/routes/admin.routes.js:1463 |
| 36 | POST | `/api/v1/admin/gmail/disconnect` | apps/payment-api/src/routes/admin.routes.js:1488 |
| 37 | POST | `/api/v1/admin/gmail/credentials` | apps/payment-api/src/routes/admin.routes.js:1540 |
| 38 | POST | `/api/v1/admin/gmail/test` | apps/payment-api/src/routes/admin.routes.js:1589 |
| 39 | GET | `/api/v1/admin/dev/false-narrative` | apps/payment-api/src/routes/admin.routes.js:1689 |
| 40 | POST | `/api/v1/admin/dev/false-narrative` | apps/payment-api/src/routes/admin.routes.js:1705 |
| 41 | GET | `/api/v1/admin/dev/test-mode` | apps/payment-api/src/routes/admin.routes.js:1739 |
| 42 | POST | `/api/v1/admin/dev/test-mode` | apps/payment-api/src/routes/admin.routes.js:1755 |
| 43 | POST | `/api/v1/admin/dev/test-mode/inject` | apps/payment-api/src/routes/admin.routes.js:1804 |
| 44 | GET | `/api/v1/admin/dev/test-mode/audit` | apps/payment-api/src/routes/admin.routes.js:1889 |
| 45 | GET | `/api/v1/admin/dev/simulator/customers` | apps/payment-api/src/routes/admin.routes.js:1958 |
| 46 | GET | `/api/v1/admin/dev/simulator/customers/:id/sessions` | apps/payment-api/src/routes/admin.routes.js:1991 |
| 47 | POST | `/api/v1/admin/dev/simulator/inject` | apps/payment-api/src/routes/admin.routes.js:2036 |
| 48 | GET | `/api/v1/admin/dev/simulator/history` | apps/payment-api/src/routes/admin.routes.js:2168 |
| 49 | GET | `/api/v1/admin/security/banned-users` | apps/payment-api/src/routes/admin.routes.js:2205 |
| 50 | GET | `/api/v1/admin/security/banned-users/:id` | apps/payment-api/src/routes/admin.routes.js:2216 |
| 51 | POST | `/api/v1/admin/security/unban` | apps/payment-api/src/routes/admin.routes.js:2230 |
| 52 | POST | `/api/v1/admin/security/manual-ban` | apps/payment-api/src/routes/admin.routes.js:2290 |
| 53 | GET | `/api/v1/admin/security/events` | apps/payment-api/src/routes/admin.routes.js:2351 |
| 54 | GET | `/api/v1/admin/security/history` | apps/payment-api/src/routes/admin.routes.js:2357 |
| 55 | GET | `/api/v1/admin/customers` | apps/payment-api/src/routes/admin.routes.js:2384 |
| 56 | POST | `/api/v1/admin/auth/login` | apps/payment-api/src/routes/auth.routes.js:49 |
| 57 | POST | `/api/v1/admin/auth/logout` | apps/payment-api/src/routes/auth.routes.js:76 |
| 58 | GET | `/api/v1/admin/auth/me` | apps/payment-api/src/routes/auth.routes.js:81 |
| 59 | GET | `/api/v1/checkout/:id/state` | apps/payment-api/src/routes/checkout.routes.js:316 |
| 60 | GET | `/api/v1/checkout/server-health` | apps/payment-api/src/routes/checkout.routes.js:619 |
| 61 | POST | `/api/v1/checkout/:id/bind-customer` | apps/payment-api/src/routes/checkout.routes.js:626 |
| 62 | POST | `/api/v1/checkout/:id/retry-new-payment` | apps/payment-api/src/routes/checkout.routes.js:800 |
| 63 | POST | `/api/v1/checkout/:id/payment-done` | apps/payment-api/src/routes/checkout.routes.js:887 |
| 64 | POST | `/api/v1/checkout/:id/cancel` | apps/payment-api/src/routes/checkout.routes.js:951 |
| 65 | POST | `/api/v1/checkout/:id/select-method` | apps/payment-api/src/routes/checkout.routes.js:980 |
| 66 | POST | `/api/v1/checkout/:id/confirm-cod` | apps/payment-api/src/routes/checkout.routes.js:1199 |
| 67 | GET | `/api/v1/checkout/:id/cod` | apps/payment-api/src/routes/checkout.routes.js:1336 |
| 68 | POST | `/api/v1/checkout/:id/expire` | apps/payment-api/src/routes/checkout.routes.js:1369 |
| 69 | POST | `/api/v1/checkout/:id/trigger-hook` | apps/payment-api/src/routes/checkout.routes.js:1395 |
| 70 | POST | `/api/v1/checkout/:id/simulate-observation` | apps/payment-api/src/routes/checkout.routes.js:1434 |
| 71 | POST | `/api/v1/checkout/:id/end-and-restart-payment` | apps/payment-api/src/routes/checkout.routes.js:1724 |
| 72 | POST | `/api/v1/checkout/:id/end-session-and-create-new` | apps/payment-api/src/routes/checkout.routes.js:1725 |
| 73 | GET | `/api/v1/checkout/:id/stream` | apps/payment-api/src/routes/checkout.routes.js:1728 |
| 74 | GET | `/api/v1/checkout/:id/events` | apps/payment-api/src/routes/checkout.routes.js:1742 |
| 75 | POST | `/api/v1/customer/check-user` | apps/payment-api/src/routes/customer.routes.js:44 |
| 76 | POST | `/api/v1/customer/send-otp` | apps/payment-api/src/routes/customer.routes.js:76 |
| 77 | POST | `/api/v1/customer/verify-otp` | apps/payment-api/src/routes/customer.routes.js:158 |
| 78 | POST | `/api/v1/customer/register` | apps/payment-api/src/routes/customer.routes.js:271 |
| 79 | POST | `/api/v1/customer/login` | apps/payment-api/src/routes/customer.routes.js:369 |
| 80 | GET | `/api/v1/customer/me` | apps/payment-api/src/routes/customer.routes.js:435 |
| 81 | POST | `/api/v1/customer/logout` | apps/payment-api/src/routes/customer.routes.js:467 |
| 82 | POST | `/api/v1/customer/switch-account` | apps/payment-api/src/routes/customer.routes.js:480 |
| 83 | GET | `/api/v1/customer/payments` | apps/payment-api/src/routes/customer.routes.js:510 |
| 84 | POST | `/api/v1/customer/tickets` | apps/payment-api/src/routes/customer.routes.js:514 |
| 85 | GET | `/api/v1/customer/tickets/:id/messages` | apps/payment-api/src/routes/customer.routes.js:518 |
| 86 | POST | `/api/v1/customer/tickets/:id/messages` | apps/payment-api/src/routes/customer.routes.js:522 |
| 87 | GET | `/api/v1/customer/tickets/:id/stream` | apps/payment-api/src/routes/customer.routes.js:526 |
| 88 | POST | `/api/v1/devices/register` | apps/payment-api/src/routes/device.routes.js:15 |
| 89 | POST | `/api/v1/devices/verify` | apps/payment-api/src/routes/device.routes.js:108 |
| 90 | GET | `/api/v1/devices/:id` | apps/payment-api/src/routes/device.routes.js:145 |
| 91 | GET | `/api/v1/devices/customer/:customerId` | apps/payment-api/src/routes/device.routes.js:166 |
| 92 | POST | `/api/v1/payments` | apps/payment-api/src/routes/payments.routes.js:30 |
| 93 | GET | `/api/v1/payments/:id` | apps/payment-api/src/routes/payments.routes.js:270 |
| 94 | GET | `/api/v1/payments/:id/cod` | apps/payment-api/src/routes/payments.routes.js:300 |
| 95 | GET | `/api/v1/orders/:orderId/cod` | apps/payment-api/src/routes/payments.routes.js:337 |
| 96 | GET | `/api/v1/pollers/active-window` | apps/payment-api/src/routes/pollers.routes.js:54 |
| 97 | POST | `/api/v1/pollers/events` | apps/payment-api/src/routes/pollers.routes.js:82 |
| 98 | POST | `/api/v1/pollers/android/pair` | apps/payment-api/src/routes/pollers.routes.js:228 |
| 99 | POST | `/api/v1/observers/sms/pair` | apps/payment-api/src/routes/pollers.routes.js:229 |
| 100 | POST | `/api/v1/pollers/android/events` | apps/payment-api/src/routes/pollers.routes.js:399 |
| 101 | POST | `/api/v1/observers/sms` | apps/payment-api/src/routes/pollers.routes.js:400 |
| 102 | POST | `/api/v1/pollers/android/heartbeat` | apps/payment-api/src/routes/pollers.routes.js:417 |
| 103 | POST | `/api/v1/pollers/heartbeat` | apps/payment-api/src/routes/pollers.routes.js:418 |
| 104 | POST | `/api/v1/observers/heartbeat` | apps/payment-api/src/routes/pollers.routes.js:419 |
| 105 | GET | `/api/v1/admin/sms-gateway/pair-qr` | apps/payment-api/src/routes/pollers.routes.js:422 |
| 106 | GET | `/api/v1/admin/sms-gateway/devices` | apps/payment-api/src/routes/pollers.routes.js:492 |
| 107 | DELETE | `/api/v1/admin/sms-gateway/devices/:deviceId` | apps/payment-api/src/routes/pollers.routes.js:526 |
| 108 | GET | `/api/v1/admin/sms-gateway/download-apk` | apps/payment-api/src/routes/pollers.routes.js:569 |
| 109 | GET | `/paykr-sms-gateway.apk` | apps/payment-api/src/routes/pollers.routes.js:570 |

## A2. Routes of the other Node services (pathname-matched handlers)

| Service | Line | Matcher |
|---|---|---|
| checkout-portal | 145 | `if (pathname === '/health') {` |
| checkout-portal | 152 | `if (pathname.startsWith('/api/')) {` |
| checkout-portal | 187 | `if (pathname === '/paykr.js' \|\| pathname === '/sdk/paykr.js' \|\| pathname === '/sdk/paykr-checkout.js') {` |
| checkout-portal | 209 | `if (pathname.startsWith('/icons/')) {` |
| checkout-portal | 226 | `const qrMatch = pathname.match(/^\/checkout\/([^/]+)\/qr\.svg$/);` |
| checkout-portal | 245 | `const checkoutMatch = pathname.match(/^\/checkout\/([^/]+)$/);` |
| merchant-admin | 83 | `if (pathname === '/health') {` |
| merchant-admin | 90 | `if (pathname === '/login') {` |
| merchant-admin | 111 | `if (pathname === '/api/admin/tickets') {` |
| merchant-admin | 124 | `if (pathname.startsWith('/api/services') \|\| pathname === '/api/logs/stream') {` |
| merchant-admin | 153 | `if (pathname.startsWith('/api/')) {` |
| merchant-admin | 186 | `if (pathname === '/paykr-sms-gateway.apk' \|\| pathname === '/api/v1/admin/sms-gateway/download-apk') {` |
| merchant-admin | 214 | `if (pathname === '/' \|\| pathname === '/admin') {` |
| coin-wallet-store | 87 | `if (pathname.startsWith('/assets/') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 105 | `if (pathname === '/health') {` |
| coin-wallet-store | 112 | `if (pathname === '/paykr.js' \|\| pathname === '/sdk/paykr-checkout.js' \|\| pathname === '/sdk/paykr.js') {` |
| coin-wallet-store | 126 | `if (pathname === '/openapi.yaml') {` |
| coin-wallet-store | 139 | `if (pathname.startsWith('/checkout') \|\| pathname.startsWith('/icons/')) {` |
| coin-wallet-store | 169 | `if (pathname.startsWith('/api/v1/')) {` |
| coin-wallet-store | 210 | `if ((pathname === '/' \|\| pathname === '/store') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 224 | `if (pathname === '/wallet' && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 238 | `if ((pathname === '/order-success' \|\| pathname === '/success') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 263 | `if ((pathname === '/order-failed' \|\| pathname === '/failed') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 282 | `if ((pathname === '/order-suspicious' \|\| pathname === '/suspicious') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 297 | `if ((pathname === '/order-expired' \|\| pathname === '/expired') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 312 | `if ((pathname === '/order-cancelled' \|\| pathname === '/cancelled') && (method === 'GET' \|\| method === 'HEAD')) {` |
| coin-wallet-store | 327 | `if (pathname === '/api/wallet-balance' && method === 'GET') {` |
| coin-wallet-store | 340 | `if (pathname === '/api/checkout' && method === 'POST') {` |
| coin-wallet-store | 395 | `if (pathname === '/api/verify-payment' && method === 'POST') {` |
| coin-wallet-store | 458 | `if ((pathname === '/api/webhook' \|\| pathname === '/webhook') && method === 'POST') {` |
| gmail-observer | 46 | `if (pathname === '/health') {` |
| gmail-observer | 59 | `if (pathname === '/stop' && method === 'POST') {` |
| gmail-observer | 67 | `if (pathname === '/oauth/url') {` |
| gmail-observer | 97 | `if (pathname === '/oauth/callback') {` |
| gmail-observer | 233 | `if (pathname === '/api/browser/status') {` |
| gmail-observer | 247 | `if (pathname === '/api/browser/auto-ensure' && method === 'POST') {` |
| gmail-observer | 263 | `if (pathname === '/api/browser/launch-login' && req.method === 'POST') {` |
| gmail-observer | 279 | `if (pathname === '/api/sweep' && req.method === 'POST') {` |
| gmail-observer | 295 | `if (pathname === '/process-email' && req.method === 'POST') {` |
| sms-observer | 43 | `if (pathname === '/health') {` |
| sms-observer | 56 | `if (pathname === '/api/browser/status') {` |
| sms-observer | 70 | `if (pathname === '/api/browser/auto-ensure' && method === 'POST') {` |
| sms-observer | 86 | `if (pathname === '/api/browser/launch-pairing' && req.method === 'POST') {` |
| sms-observer | 102 | `if (pathname === '/api/sweep' && method === 'POST') {` |
| sms-observer | 115 | `if (pathname === '/process-sms' && req.method === 'POST') {` |
| control-center | 61 | `if (pathname === '/health') {` |
| control-center | 68 | `if (pathname === '/api/services' && method === 'GET') {` |
| control-center | 78 | `if (pathname === '/api/services/start-all' && method === 'POST') {` |
| control-center | 86 | `if (pathname === '/api/services/stop-all' && method === 'POST') {` |
| control-center | 94 | `const startMatch = pathname.match(/^\/api\/services\/([a-zA-Z0-9_\-]+)\/start$/);` |
| control-center | 109 | `const stopMatch = pathname.match(/^\/api\/services\/([a-zA-Z0-9_\-]+)\/stop$/);` |
| control-center | 124 | `const restartMatch = pathname.match(/^\/api\/services\/([a-zA-Z0-9_\-]+)\/restart$/);` |
| control-center | 139 | `if (pathname === '/api/logs/stream') {` |
| control-center | 166 | `if (pathname === '/' \|\| pathname === '/dashboard') {` |
| payment-help | 57 | `if (pathname === '/health') {` |
| payment-help | 64 | `if (pathname === '/api/customer/tickets') {` |
| payment-help | 74 | `if (pathname === '/' \|\| pathname === '/support' \|\| pathname === '/help') {` |

## A3. Classes and public methods (domain logic to port)

- **apps/control-center/src/index.js** — exports: `startControlCenter`
- **apps/control-center/src/process-manager.js** — exports: `ProcessManager`; methods: `appendLog`, `getService`, `getServicesStatus`, `startService`, `stopService`, `restartService`, `startAll`, `stopAll`
- **apps/control-center/src/server.js** — exports: `ControlCenterServer`; methods: `broadcastSse`, `createServer`
- **apps/gmail-observer/src/browser-automator.js** — exports: `GmailBrowserAutomator`; methods: `fetchActivePaymentAmounts`, `loadStoredLoginState`, `saveStoredLoginState`, `autoEnsureConnection`, `launchLoginWindow`, `launchBackgroundObserver`, `startStateMonitor`, `startExtractionLoop`, `pollInboxEmails`, `getStatus`, `stop`
- **apps/gmail-observer/src/gmail-poller.js** — exports: `GmailPoller`; methods: `setCredentials`, `fetchCredentialsFromPaymentApi`, `getAccessToken`, `resolveActiveReceivingAccount`, `processEmail`, `start`, `stop`, `pollCycle`
- **apps/gmail-observer/src/index.js** — exports: `startGmailObserver`
- **apps/gmail-observer/src/oauth-view.js** — exports: `renderOAuthResult`
- **apps/gmail-observer/src/parser.js** — exports: `extractIntelligentAmount`, `BankEmailParser`; methods: `parse`
- **apps/gmail-observer/src/server.js** — exports: `GmailObserverServer`; methods: `createServer`
- **apps/payment-api/src/services/tunnel-manager.js** — exports: `TunnelManager`, `globalTunnelManager`; methods: `appendLog`, `getStatus`, `start`, `stop`
- **apps/payment-api/src/services/whatsapp-berry.service.js** — exports: `WhatsAppBerryService`, `getWhatsAppBerryService`; methods: `restoreSessionMeta`, `ensureDirectories`, `cleanPhoneNumber`, `formatJid`, `cleanSessionFiles`, `waitForQrCode`, `initialize`, `setupListeners`, `getStatus`, `getQrCode`, `getClientInfo`, `getPairingCode`, `disconnect`, `reconnect`, `requestPairingCode`, `sendOtp`, `sendMessage`
- **apps/sms-observer/src/browser-automator.js** — exports: `GoogleMessagesAutomator`; methods: `fetchActivePaymentAmounts`, `loadStoredPairedState`, `saveStoredPairedState`, `autoEnsureConnection`, `launchPairingWindow`, `launchBackgroundObserver`, `startStateMonitor`, `startExtractionLoop`, `pollRecentMessages`, `getStatus`, `stop`
- **apps/sms-observer/src/index.js** — exports: `startSmsObserver`
- **apps/sms-observer/src/server.js** — exports: `SmsObserverServer`; methods: `createServer`
- **apps/sms-observer/src/sms-parser.js** — exports: `extractIntelligentAmount`, `BankSmsParser`; methods: `parse`
- **apps/sms-observer/src/sms-service.js** — exports: `SmsObserverService`; methods: `resolveActiveReceivingAccount`, `processSms`, `start`, `stop`, `pollCycle`
- **packages/common/src/admin-auth.js** — exports: `ADMIN_COOKIE`, `INTERNAL_TOKEN_HEADER`, `getAdminCredentials`, `checkAdminCredentials`, `issueAdminToken`, `verifyAdminToken`, `readAdminToken`, `getAdminSession`, `adminCookieHeader`, `clearAdminCookieHeader`, `internalServiceToken`, `internalHeaders`, `isInternalRequest`, `isPrivateGatewayPath`, `createLoginLimiter`
- **packages/common/src/contracts.js** — exports: `DefaultBranding`, `normalizeEmail`, `isValidEmail`, `normalizeMobile`, `isValidMobile`, `isValidPassword`
- **packages/common/src/crypto.js** — exports: `encrypt`, `decrypt`, `generateHmac`, `verifyHmac`, `generateRandomToken`, `hashPassword`, `verifyPassword`
- **packages/common/src/enums.js** — exports: `PaymentStatus`, `PublicPaymentOutcome`, `PaymentMethod`, `SellingMode`, `ObserverSource`, `ObservationAttributionStatus`, `TicketStatus`, `SenderType`, `AuditActorType`
- **packages/common/src/money.js** — exports: `toPaise`, `toRupeesString`, `formatINR`
- **packages/common/src/observation.js** — exports: `calculateObservationFingerprint`
- **packages/common/src/runtime-config.js** — exports: `SERVICE_PORTS`, `deriveCloudflareRoutes`, `resolveRuntimeUrls`, `createPairingPayload`, `validatePairingPayload`, `resolvePairingUrl`, `getGmailRedirectUri`
- **packages/common/src/upi-providers.js** — exports: `UPI_PROVIDERS`, `getUpiProviders`, `findUpiProviderByPackage`, `findUpiProviderById`
- **packages/common/src/upi.js** — exports: `isValidVpa`, `buildUpiUri`, `buildUpiIntentUri`
- **packages/core/src/attribution-engine.js** — exports: `AttributionEngine`; methods: `destroy`, `processObservation`, `flagLateObservation`
- **packages/core/src/bank-email-parser.js** — exports: `BankEmailParser`; methods: `parse`
- **packages/core/src/bank-sms-parser.js** — exports: `extractIntelligentAmount`, `BankSmsParser`; methods: `parse`
- **packages/core/src/device-security.js** — exports: `normalizeDeviceSignals`, `generateFingerprintHash`, `importDevicePublicKey`, `verifyDeviceSignature`, `validateSessionDeviceAccess`
- **packages/core/src/normalizer.js** — exports: `extractIntelligentPaise`, `ObservationNormalizer`; methods: `normalize`
- **packages/core/src/qr.js** — exports: `QrCode`, `generateQrSvg`, `generateQrMatrix`; methods: `encode`
- **packages/core/src/queue-engine.js** — exports: `QueueEngine`; methods: `enqueueOrActivate`, `promoteNext`, `calculateEstimatedWaitSeconds`
- **packages/core/src/recovery-engine.js** — exports: `RecoveryEngine`; methods: `recover`
- **packages/core/src/state-machine.js** — exports: `VALID_TRANSITIONS`, `isValidTransition`, `assertValidTransition`, `isTerminalStatus`, `toPublicOutcome`
- **packages/core/src/timing-engine.js** — exports: `TimingEngine`; methods: `startActiveTimer`, `handleActiveTimerExpiration`, `cancelActiveTimer`, `handlePaymentDone`, `handleProcessingTimerExpiration`, `handleCancel`, `clearTimers`, `destroy`
- **packages/core/src/verification-pipeline.js** — exports: `PaymentVerificationPipeline`; methods: `process`
- **packages/core/src/webhook-engine.js** — exports: `WebhookEngine`; methods: `dispatch`, `retryPending`
- **packages/db/src/database.js** — exports: `ensureDatabaseInitialized`, `Database`, `getDatabase`; methods: `normalizeSql`, `execute`, `run`, `query`, `queryOne`, `exec`, `transaction`, `close`
- **packages/db/src/migrate.js** — exports: `runMigrations`, `reinitializeCustomerIdentity`
- **packages/db/src/repositories/audit-log.repository.js** — exports: `AuditLogRepository`; methods: `log`, `findByEntity`, `findByMerchant`, `mapRow`
- **packages/db/src/repositories/customer-session.repository.js** — exports: `CustomerSessionRepository`; methods: `create`, `findByToken`, `revokeToken`, `revokeAllForCustomer`, `countActive`
- **packages/db/src/repositories/customer.repository.js** — exports: `CustomerRepository`; methods: `create`, `findById`, `findByEmail`, `findByMobile`, `findByPhone`, `findByIdentifier`, `verifyCredentials`, `setOtp`, `verifyOtp`, `clearAll`, `count`, `banCustomer`, `unbanCustomer`, `isBanned`, `createSecurityEvent`, `getSecurityEvents`, `findAllBanned`, `getBanEvidence`, `mapRow`
- **packages/db/src/repositories/device.repository.js** — exports: `DeviceRepository`; methods: `create`, `findById`, `findByToken`, `findByPublicKey`, `findByCustomerAndFingerprint`, `findByCustomerId`, `associateCustomer`, `touch`, `update`, `mapRow`
- **packages/db/src/repositories/merchant.repository.js** — exports: `MerchantRepository`; methods: `create`, `findById`, `findBySlug`, `getDefault`, `findAll`, `update`, `mapRow`
- **packages/db/src/repositories/observation.repository.js** — exports: `ObservationRepository`; methods: `create`, `findById`, `findByFingerprint`, `findUnattributed`, `findByReceivingAccount`, `markAttributed`, `markDuplicate`, `markLateUnmatched`, `mapRow`
- **packages/db/src/repositories/payment-session.repository.js** — exports: `PaymentSessionRepository`; methods: `create`, `findById`, `findByOrderId`, `findByCustomerId`, `findByMerchantId`, `acquireSlotAtomic`, `transitionToProcessing`, `transitionToTerminal`, `findCurrentActiveOrProcessing`, `findCurrentActiveOrProcessingForMerchant`, `findActiveForCustomer`, `findActiveForDevice`, `findConflictingActiveSession`, `findAllActiveOrPendingForCustomer`, `findQueuedSessions`, `getNextQueuedSession`, `reindexQueue`, `findRecentlyExpiredOrFailed`, `recordVerificationSource`, `bindDevice`, `mapRow`
- **packages/db/src/repositories/receiving-account.repository.js** — exports: `ReceivingAccountRepository`; methods: `create`, `findById`, `findByMerchantId`, `findActiveForMerchant`, `activateAccount`, `deactivateAccount`, `updateAccount`, `deleteAccount`, `setSlotStatus`, `upsertActiveForMerchant`, `mapRow`
- **packages/db/src/repositories/support-ticket.repository.js** — exports: `SupportTicketRepository`; methods: `createTicket`, `findTicketById`, `findTicketsByCustomer`, `findTicketsByMerchant`, `updateTicketStatus`, `addMessage`, `findMessageById`, `findMessagesByTicket`, `mapTicketRow`, `mapMessageRow`
- **packages/merchant-sdk/src/client.js** — exports: `PaykrPaymentClient`; methods: `createPayment`, `getPayment`, `verifyWebhook`
- **packages/merchant-sdk/src/paykr-checkout.js** — exports: `PaykrCheckout`, `Paykr`
- **packages/observer-sdk/src/browser-launcher.js** — exports: `BrowserLauncher`; methods: `findBrowserExecutable`, `launch`, `getTargets`, `closeTarget`, `closeBrowser`, `evaluate`
- **packages/observer-sdk/src/client.js** — exports: `PaymentObserverClient`; methods: `submitObservation`, `submitAndroidSigned`, `submitHeartbeat`

## A4. Audit-log actions written (`audit_logs.action`)

`ADMIN_LOGIN`, `ADMIN_LOGIN_FAILED`, `ALL_SETTINGS_UPDATED_ATOMIC`, `COD_ORDER_PLACED`, `COUNTER_MANUAL_PAYMENT_CONFIRMED`, `COUNTER_MANUAL_PAYMENT_DECLINED`, `CRASH_RECOVERY_EXPIRED_ACTIVE`, `CRASH_RECOVERY_EXPIRED_PROCESSING`, `CUSTOMER_AUTHENTICATED_AND_BOUND`, `CUSTOMER_AUTOMATICALLY_BANNED`, `CUSTOMER_CLICKED_PAYMENT_DONE`, `CUSTOMER_LOGGED_IN`, `CUSTOMER_MANUALLY_BANNED`, `CUSTOMER_REGISTERED`, `CUSTOMER_WHATSAPP_OTP_LOGGED_IN`, `DEVICE_REGISTERED`, `DUPLICATE_OBSERVATION_DROPPED`, `END_SESSION_AND_CREATE_NEW`, `HOOK_<ACTION> (from trigger-hook)`, `LATE_OBSERVATION_DETECTED`, `MERCHANT_SETTINGS_UPDATED`, `PAYMENT_ATTRIBUTED_SUCCESS_L1`, `PAYMENT_CANCELLED_BY_USER`, `PAYMENT_ENQUEUED`, `PAYMENT_EXPIRED_TIMEOUT`, `PAYMENT_FAILED_NO_OBSERVATION`, `PAYMENT_LEVEL1_VERIFIED`, `PAYMENT_METHOD_SWITCHED`, `PAYMENT_SESSION_RECREATED_ON_NEW_DEVICE`, `PAYMENT_SLOT_ACTIVATED`, `PAYMENT_VERIFICATION_UPGRADED`, `PAYMENT_VERIFICATION_UPGRADED_L2`, `QUEUE_CLEARED_MANUALLY`, `QUEUE_PROMOTION_TO_ACTIVE`, `RECEIVING_UPI_ACTIVATED`, `RECEIVING_UPI_ADDED`, `RECEIVING_UPI_DELETED`, `RECEIVING_UPI_UPDATED`, `SIMULATED_OBSERVATION_INJECTED`, `SMS_GATEWAY_DEVICE_PAIRED`, `TEST_MODE_DISABLED`, `TEST_MODE_ENABLED`, `TEST_MODE_FRAUD_FLAGGED_BAN_SUPPRESSED`, `TEST_OBSERVATION_INJECTED`, `USER_UNBANNED`

## A5. Internal domain events (`eventEmitter.emit`)

- `PAYMENT.ACTION_TRIGGERED` — apps/payment-api/src/routes/checkout.routes.js
- `PAYMENT.CANCELLED` — packages/core/src/timing-engine.js
- `PAYMENT.EXPIRED` — packages/core/src/timing-engine.js
- `PAYMENT.FAILED` — apps/payment-api/src/routes/admin.routes.js, packages/core/src/timing-engine.js
- `PAYMENT.FRAUD_BLOCKED` — packages/core/src/verification-pipeline.js
- `PAYMENT.LATE_DETECTED` — packages/core/src/attribution-engine.js
- `PAYMENT.LEVEL1_VERIFIED` — packages/core/src/attribution-engine.js
- `PAYMENT.METHOD_SWITCHED` — apps/payment-api/src/routes/checkout.routes.js
- `PAYMENT.SUCCESS` — apps/payment-api/src/routes/admin.routes.js, packages/core/src/attribution-engine.js
- `PAYMENT.VERIFICATION_UPGRADED` — packages/core/src/attribution-engine.js
- `QUEUE.PROMOTED` — packages/core/src/queue-engine.js
- `SESSION.ACTIVATED` — packages/core/src/queue-engine.js
- `SESSION.CANCELLED` — packages/core/src/timing-engine.js
- `SESSION.EXPIRED` — packages/core/src/timing-engine.js
- `SESSION.FAILED` — packages/core/src/timing-engine.js
- `SESSION.PROCESSING` — packages/core/src/timing-engine.js
- `SESSION.QUEUED` — packages/core/src/queue-engine.js

## A6. Server-Sent Events broadcast (`broadcastSse(topic, event)`)

- topic 'admin_counter_events' → event `counter_payment_requested`
- topic 'admin_counter_events' → event `status_change`
- topic `queue_${oldReceivingAccountId}` → event `queue_update`
- topic `queue_${receivingAccountId}` → event `queue_update`
- topic c.id → event `status_change`
- topic data.paymentId → event `status_change`
- topic data.sessionId → event `account_blocked`
- topic data.sessionId → event `status_change`
- topic newSession.id → event `status_change`
- topic params.id → event `status_change`
- topic paymentId → event `state_change`
- topic paymentId → event `status_change`
- topic promoted.id → event `status_change`
- topic row.id → event `status_change`
- topic s.id → event `state_change`
- topic s.id → event `status_change`
- topic sId → event `status_change`
- topic session.id → event `account_blocked`
- topic session.id → event `status_change`

Topics: per payment session id; `admin_counter_events` (admin live feed); `queue_<receivingAccountId>`.

## A7. Environment variables read by the code

| Variable | Read in |
|---|---|
| `ADMIN_EMAIL` | packages/common/src/admin-auth.js |
| `ADMIN_PASSWORD` | packages/common/src/admin-auth.js |
| `ADMIN_SESSION_SECRET` | packages/common/src/admin-auth.js |
| `ANDROID_ENROLLMENT_SECRET` | apps/android-observer-app/simulate-notification.js, apps/payment-api/src/routes/pollers.routes.js |
| `BASE_CHECKOUT_URL` | apps/payment-api/src/index.js |
| `BASE_PAYMENT_API_URL` | apps/android-observer-app/driver.js, apps/android-observer-app/simulate-notification.js, apps/checkout-portal/src/server.js, apps/coin-wallet-store/src/server.js (+8) |
| `CHECKOUT_PORT` | apps/coin-wallet-store/src/server.js |
| `CHECKOUT_PORTAL_PORT` | apps/checkout-portal/src/index.js, apps/control-center/src/process-manager.js, apps/merchant-admin/src/server.js |
| `CLOUDFLARE_DOMAIN` | packages/common/src/runtime-config.js, scripts/start.js |
| `CLOUDFLARE_TUNNEL_COMMAND` | scripts/dev.js, scripts/start.js |
| `COIN_STORE_PORT` | apps/coin-wallet-store/src/index.js, apps/control-center/src/process-manager.js |
| `COIN_STORE_WEBHOOK_SECRET` | packages/db/src/migrate.js |
| `COIN_STORE_WEBHOOK_URL` | packages/db/src/migrate.js |
| `CONTROL_CENTER_PORT` | apps/control-center/src/index.js, apps/merchant-admin/src/server.js |
| `DEFAULT_APP_NAME` | apps/merchant-admin/src/server.js |
| `DEPLOYMENT_PREFIX` | packages/common/src/runtime-config.js, scripts/start.js |
| `DISPLAY` | packages/observer-sdk/src/browser-launcher.js |
| `ENABLE_LEGACY_CHROME_OBSERVERS` | apps/control-center/src/process-manager.js, apps/gmail-observer/src/browser-automator.js, apps/sms-observer/src/browser-automator.js |
| `GMAIL_CLIENT_ID` | apps/gmail-observer/src/server.js, apps/payment-api/src/routes/admin.routes.js |
| `GMAIL_CLIENT_SECRET` | apps/gmail-observer/src/server.js |
| `GMAIL_OBSERVER_PORT` | apps/control-center/src/process-manager.js, apps/gmail-observer/src/index.js, apps/payment-api/src/index.js, apps/payment-api/src/routes/admin.routes.js (+2) |
| `GMAIL_POLL_INTERVAL_MS` | apps/gmail-observer/src/gmail-poller.js |
| `GMAIL_REDIRECT_URI` | packages/common/src/runtime-config.js |
| `HELP_PORTAL_PORT` | apps/payment-help/src/index.js |
| `INITIAL_ADMIN_EMAIL` | packages/common/src/admin-auth.js |
| `INITIAL_ADMIN_PASSWORD` | packages/common/src/admin-auth.js |
| `JWT_SECRET` | packages/common/src/admin-auth.js |
| `LOCALAPPDATA` | packages/observer-sdk/src/browser-launcher.js |
| `MERCHANT_ADMIN_PORT` | apps/control-center/src/process-manager.js, apps/merchant-admin/src/index.js |
| `NODE_ENV` | apps/payment-api/src/routes/admin.routes.js, apps/payment-api/src/routes/customer.routes.js, apps/payment-api/src/routes/pollers.routes.js, apps/payment-api/src/services/whatsapp-berry.service.js (+5) |
| `PAYKR_MODE` | packages/common/src/runtime-config.js, scripts/dev.js, scripts/start.js |
| `PAYMENT_ACTIVE_WINDOW_MS` | apps/payment-api/src/index.js |
| `PAYMENT_API_INTERNAL_URL` | apps/coin-wallet-store/src/server.js |
| `PAYMENT_API_PORT` | apps/checkout-portal/src/server.js, apps/coin-wallet-store/src/server.js, apps/control-center/src/process-manager.js, apps/gmail-observer/src/server.js (+2) |
| `PAYMENT_GRACE_PERIOD_MS` | packages/core/src/attribution-engine.js |
| `PAYMENT_PROCESSING_WINDOW_MS` | apps/payment-api/src/index.js |
| `SMS_OBSERVER_POLL_INTERVAL_MS` | apps/sms-observer/src/sms-service.js |
| `SMS_OBSERVER_PORT` | apps/control-center/src/process-manager.js, apps/payment-api/src/index.js, apps/payment-api/src/routes/admin.routes.js, apps/payment-api/src/routes/checkout.routes.js (+1) |
| `SQLITE_DB_PATH` | packages/db/src/database.js |
| `WEBHOOK_MAX_RETRIES` | apps/payment-api/src/index.js |
| `WEBHOOK_RETRY_INTERVAL_MS` | apps/payment-api/src/index.js |

## A8. Existing automated test cases (each one is an acceptance criterion for the Laravel build)

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
- [ ] GET /checkout/:id renders responsive HTML with injected payment ID
- [ ] GET /checkout/:id/qr.svg returns dynamic SVG image
- [ ] GET /icons/:filename returns official Play Store icons with correct MIME type
- [ ] GET /paykr.js and /sdk/paykr-checkout.js return official integration SDK with CORS
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
- [ ] Switching from UPI to COD decouples UPI queue, promotes queued customer, and starts parallel 5-min timer
- [ ] COD session expires after parallel 5-minute window without touching UPI queue

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
- [ ] converts valid rupee strings and numbers to integer paise
- [ ] rejects negative, zero, and malformed currency inputs
- [ ] formats paise into standard decimal string and INR symbol
- [ ] encrypts and decrypts with AES-256-GCM successfully
- [ ] fails decryption if ciphertext or auth tag is tampered
- [ ] generates and verifies HMAC-SHA256 signatures with timing safety
- [ ] hashes and verifies passwords securely
- [ ] validates UPI VPAs correctly
- [ ] builds canonical upi://pay URI dynamically with server amount
- [ ] strictly excludes cu, tr and all extra parameters even when currency option is passed
- [ ] builds valid Android intent URI containing only canonical UPI parameters
- [ ] validates 30 official UPI application provider registry
- [ ] generates consistent fingerprints with bank reference numbers
- [ ] generates cluster fingerprints within same 60-second window when no reference

### packages/core/src/__tests__/core.test.js
- [ ] permits valid transitions and denies illegal transitions
- [ ] maps internal terminal states to public contract outcomes
- [ ] extracts monetary amounts from various notification formats
- [ ] normalizes explicit amount in paise directly
- [ ] intelligently extracts amounts without standard currency symbols e.g. "20.00 received"
- [ ] attributes matching observation to active processing session
- [ ] deduplicates multi-channel observations (SMS + Gmail)
- [ ] CRITICAL INVARIANT: Late observation does not attach to a new queued customer

### packages/core/src/__tests__/queue-timing.test.js
- [ ] Queue Engine correctly assigns active slot and queued positions with wait estimates
- [ ] Timing Engine: 5-minute active window expires and auto-promotes next in queue
- [ ] Timing Engine: Payment Done starts 30-sec processing window, fails if no observation
- [ ] Recovery Engine: crash recovery cleans up overdue sessions and promotes queued

### packages/core/src/__tests__/webhook-engine.test.js
- [ ] successfully dispatches HMAC-SHA256 signed webhook on payment event
- [ ] records failure and supports retry on merchant HTTP 500 error

### packages/db/src/__tests__/auth-matrix.test.js
- [ ] Full 53-Point Customer Identity, Authentication & Payment Invariant Matrix

### packages/db/src/__tests__/customer-reset.test.js
- [ ] Production & Development Database Safe Customer Reset and Zero Residue Invariant

### packages/db/src/__tests__/db.test.js
- [ ] migrations seed default merchant and active UPI receiving account
- [ ] customer registration and password verification
- [ ] enforces ONE active slot at one time and queues subsequent sessions
- [ ] transitions to CANCELLED and immediately releases slot
- [ ] records observations and retrieves by fingerprint for deduplication
- [ ] creates tickets, links payments, and records bidirectional chat
- [ ] records and queries audit trail

### packages/db/src/__tests__/sqlite_verify.test.js
- [ ] native node:sqlite operates in memory

### packages/merchant-sdk/src/__tests__/merchant-sdk.test.js
- [ ] creates a payment session using SDK client
- [ ] queries payment status and details
- [ ] successfully verifies authentic HMAC-SHA256 webhook payload
- [ ] rejects tampered webhook payload or incorrect secret
- [ ] rejects malformed signature header

### packages/merchant-sdk/src/__tests__/paykr-universal-integration.test.js
- [ ] SDK exports both PaykrCheckout and Paykr aliases
- [ ] Paykr.pay creates payment session and resolves checkoutUrl on backend
- [ ] Button attribute extractor correctly parses customer and metadata attributes

### scripts/chaos-concurrency.test.js
- [ ] CHAOS 1: 10 simultaneous sessions for identical amounts (₹200) acquire slot deterministically
- [ ] CHAOS 2: Multi-tab simultaneous Payment Done clicks are strictly idempotent
- [ ] CHAOS 3: Dual-channel flood (Gmail + SMS) guarantees exactly 1 payment attribution
- [ ] CHAOS 4: Promotion cascade: completed session immediately unblocks waiting queue in order
- [ ] CHAOS 5: Late observation does not hijack currently active customer session

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

Total: 249 test cases.

## A9. Current database schema (SQLite) — source of truth for the MySQL migrations

```sql
-- SQLite Schema for Paykr
-- Native Local / Zero-Dependency Execution

-- 1. Merchants Table
CREATE TABLE IF NOT EXISTS merchants (
    id TEXT PRIMARY KEY,
    business_name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    contact_email TEXT NOT NULL,
    contact_phone TEXT NOT NULL,
    webhook_url TEXT,
    webhook_secret TEXT NOT NULL,
    branding TEXT NOT NULL DEFAULT '{"primaryColor":"#2563eb","appName":"Paykr","logoUrl":null}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 2. Payment Receiving Accounts
CREATE TABLE IF NOT EXISTS payment_receiving_accounts (
    id TEXT PRIMARY KEY,
    merchant_id TEXT NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    upi_id TEXT NOT NULL,
    payee_name TEXT NOT NULL,
    is_merchant_enabled INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_slot_busy INTEGER NOT NULL DEFAULT 0,
    current_active_session_id TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_single_active_vpa_per_merchant ON payment_receiving_accounts (merchant_id) WHERE is_active = 1;

-- 3. Customers Table (Gateway-owned Customer Identity with WhatsApp Berry OTP)
CREATE TABLE IF NOT EXISTS customers (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    mobile_number TEXT UNIQUE NOT NULL,
    password_hash TEXT,
    otp_code TEXT,
    otp_expires_at TEXT,
    is_phone_verified INTEGER DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'ACTIVE',
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_email ON customers (email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_mobile ON customers (mobile_number);

-- 3b. Customer Sessions Table (Server-authoritative session engine)
CREATE TABLE IF NOT EXISTS customer_sessions (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    token TEXT UNIQUE NOT NULL,
    expires_at TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_customer_sessions_token ON customer_sessions (token, is_active);
CREATE INDEX IF NOT EXISTS idx_customer_sessions_customer ON customer_sessions (customer_id);

-- 4. Payment Sessions Table
CREATE TABLE IF NOT EXISTS payment_sessions (
    id TEXT PRIMARY KEY,
    merchant_id TEXT NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    customer_id TEXT REFERENCES customers(id) ON DELETE SET NULL,
    receiving_account_id TEXT REFERENCES payment_receiving_accounts(id),
    order_id TEXT NOT NULL,
    amount_paise INTEGER NOT NULL CHECK (amount_paise > 0),
    currency TEXT NOT NULL DEFAULT 'INR',
    method TEXT NOT NULL DEFAULT 'UPI',
    status TEXT NOT NULL DEFAULT 'INITIATED',
    queue_position INTEGER DEFAULT 0,
    slot_acquired_at TEXT,
    expires_at TEXT,
    processing_started_at TEXT,
    processing_expires_at TEXT,
    completed_at TEXT,
    return_url TEXT,
    attributed_observation_id TEXT,
    idempotency_key TEXT,
    device_id TEXT REFERENCES devices(id),
    verification_level INTEGER DEFAULT 0,
    verification_sources TEXT DEFAULT '[]',
    verification_tag TEXT DEFAULT 'UNVERIFIED',
    metadata TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(merchant_id, order_id)
);

CREATE INDEX IF NOT EXISTS idx_payment_sessions_active ON payment_sessions (receiving_account_id, status);

-- 5. Payment Observations Table
CREATE TABLE IF NOT EXISTS payment_observations (
    id TEXT PRIMARY KEY,
    receiving_account_id TEXT NOT NULL REFERENCES payment_receiving_accounts(id) ON DELETE CASCADE,
    source TEXT NOT NULL,
    amount_paise INTEGER NOT NULL,
    observed_at TEXT NOT NULL,
    ingested_at TEXT NOT NULL DEFAULT (datetime('now')),
    sender_vpa TEXT,
    bank_reference_number TEXT,
    fingerprint_hash TEXT NOT NULL,
    raw_payload TEXT NOT NULL DEFAULT '{}',
    attribution_status TEXT NOT NULL DEFAULT 'UNATTRIBUTED',
    attributed_payment_id TEXT REFERENCES payment_sessions(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_observations_match ON payment_observations (receiving_account_id, amount_paise, attribution_status);

-- 6. Android Devices Table
CREATE TABLE IF NOT EXISTS android_devices (
    id TEXT PRIMARY KEY,
    merchant_id TEXT NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    device_name TEXT NOT NULL,
    device_model TEXT,
    signing_key_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_heartbeat_at TEXT,
    last_observation_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 7. Webhook Deliveries Table
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id TEXT PRIMARY KEY,
    merchant_id TEXT NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    payment_id TEXT NOT NULL REFERENCES payment_sessions(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    endpoint_url TEXT NOT NULL,
    response_status INTEGER,
    response_body TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 1,
    is_successful INTEGER NOT NULL DEFAULT 0,
    next_retry_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 8. Support Tickets & Ticket Messages
CREATE TABLE IF NOT EXISTS support_tickets (
    id TEXT PRIMARY KEY,
    merchant_id TEXT NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    customer_id TEXT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    payment_id TEXT REFERENCES payment_sessions(id) ON DELETE SET NULL,
    subject TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'OPEN',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ticket_messages (
    id TEXT PRIMARY KEY,
    ticket_id TEXT NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
    sender_type TEXT NOT NULL,
    sender_id TEXT NOT NULL,
    message TEXT NOT NULL,
    attachments TEXT DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 9. Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id TEXT PRIMARY KEY,
    merchant_id TEXT REFERENCES merchants(id) ON DELETE CASCADE,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    action TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    actor_id TEXT,
    details TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 10. Security Events Table (Forensic evidence for fraud, automatic and manual bans)
CREATE TABLE IF NOT EXISTS security_events (
    id TEXT PRIMARY KEY,
    merchant_id TEXT,
    customer_id TEXT,
    payment_session_id TEXT,
    event_type TEXT NOT NULL,
    source TEXT,
    sender TEXT,
    device_info TEXT,
    raw_message TEXT NOT NULL,
    normalized_message TEXT,
    extracted_amount TEXT,
    extracted_reference TEXT,
    parser_results TEXT DEFAULT '{}',
    verification_results TEXT DEFAULT '{}',
    fraud_reason TEXT NOT NULL,
    metadata TEXT DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_security_events_customer ON security_events (customer_id);
CREATE INDEX IF NOT EXISTS idx_security_events_session ON security_events (payment_session_id);
CREATE INDEX IF NOT EXISTS idx_security_events_type ON security_events (event_type);

-- 11. Devices Table (Cryptographic Device Binding & Hardware Context Fingerprinting)
CREATE TABLE IF NOT EXISTS devices (
    id TEXT PRIMARY KEY,
    customer_id TEXT REFERENCES customers(id) ON DELETE SET NULL,
    public_key TEXT NOT NULL,
    fingerprint_hash TEXT NOT NULL,
    user_agent TEXT,
    platform TEXT,
    browser TEXT,
    timezone TEXT,
    language TEXT,
    screen_signature TEXT,
    device_token TEXT UNIQUE,
    status TEXT NOT NULL DEFAULT 'ACTIVE',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_seen_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_devices_customer ON devices (customer_id);
CREATE INDEX IF NOT EXISTS idx_devices_fingerprint ON devices (fingerprint_hash);
CREATE INDEX IF NOT EXISTS idx_devices_token ON devices (device_token);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_device ON payment_sessions (device_id);
```

Columns added at runtime by `packages/db/src/migrate.js` (must exist in MySQL): `customers.ban_reason`, `customers.banned_at`, `customers.unbanned_at`, `customers.last_device`, `payment_receiving_accounts.is_merchant_enabled`.
