# Android driver fixture

`driver.mjs` is copied from `YouPI-PG/apps/android-observer-app/driver.js`.
Original SHA-256: `42990a67756cefccd3f1972d52e76fd88a81bd15ec786b4c2184f53143a95eb7`.
Only the `@paykr/common` import was replaced with Node's equivalent SHA-256 HMAC helper.
Request paths, JSON serialization, header names and response consumption are unchanged.
`tests/Support/android-driver-replay.mjs` captures its real fetch calls without network access;
Pest replays those exact raw bodies and signatures against Laravel.

The shipped APK is copied unchanged to `storage/app/private/releases/paykr-sms-gateway.apk`.
SHA-256: `aa057e5e3c2ae60a48d66d846c960272bc5b65eef8c3cce16f5953808a97405e`.
The APK hash assertion is a pinned migration fixture; update it deliberately when replacing the
release. The byte-level download and request contracts are tested, not execution on physical hardware.
