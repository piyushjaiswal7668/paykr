# Paykr Observer 2.0.0

Reviewed Android companion for the Laravel Paykr backend. Build output: `build/paykr-observer-2.0.0.apk`. Package `com.paykr.observer`, Android 8+ (API 26), target API 35. It installs separately from the old `com.youpi.observer` APK. Stop/unpair the old app before using this one to avoid duplicate collection.

## What it does

- Pairs using Laravel's existing short-lived merchant QR or manual enrollment token, after showing the destination for confirmation.
- Observes new incoming bank credit SMS, assembles multipart messages and forwards original sender, text and observation time. Does not read historical SMS. Filters out typical OTPs and numeric personal senders; filters are heuristic, not a guarantee of content classification.
- Optionally forwards payment-like notifications from explicitly selected package IDs. Disabled by default. Android notification access is broad at OS level; filtering occurs inside this app. Notification evidence is not proven to verify bank payments: Laravel still labels Android events SMS and requires its normal bank/account matching.
- Signs SMS events and heartbeats using Laravel's existing HMAC contract, with a fresh request timestamp on each attempt.
- Encrypts enrollment and queued payloads with an Android Keystore AES-GCM key. Device identifiers and queue timing metadata remain unencrypted. No message/key logging, backups, cleartext HTTP, redirects, analytics or third-party network endpoints.
- Uses a visible foreground notification with Stop. Stop pauses capture and queued delivery; already in-flight requests may finish. Unpair deletes local credentials and pending rows, but does not revoke the device on the server.
- Retries transient failures with backoff; authentication/clock failures remain retryable. Other permanent 4xx responses are marked rejected and shown in the queue count. Queue limit: 500 observations, retained up to three days while processing runs. When stopped, retention cleanup resumes on the next enqueue/drain. Unpair clears all rows immediately. No silent eviction when full.

## Install and test on your phone

1. Provide a trusted HTTPS root URL for Laravel on port 443, reachable from the phone. `http://localhost:8000` will not work. No tunnel has been enabled by this build.
2. Install `build/paykr-observer-2.0.0.apk` on the merchant Android phone. Do not install `device-checks.apk`; it is emulator-only test instrumentation.
3. Generate a fresh Android enrollment QR/token from Laravel for the intended merchant. Verify the displayed HTTPS server and merchant before pairing. QR camera access is requested only when scanning.
4. Accept collection consent, grant incoming SMS permission, then tap Start observation. Notification access/package selection is optional.
5. For a harmless transport test, send exactly `PAYKR OBSERVER TEST phone-check` to this phone by SMS. It carries no payment amount and must not verify a payment. Confirm the observation on the Laravel server.
6. Separately test an authorized real bank credit, offline retry, screen-off/background delivery, reboot, Stop and unpair. These real-phone acceptance checks remain pending. Android vendor battery restrictions can delay delivery.

No Google Messages login, browser extension or Node backend is needed for this Android transport. Existing Laravel observers endpoints provide pairing, event ingestion and heartbeat. The app does not bypass server payment verification.

## Build and checks

Requirements: Python 3, JDK 17, Android SDK platform 35/build-tools 35.0.0. Set `JAVA_HOME` and `ANDROID_HOME` if not using the defaults in `build.py`.

```sh
python3 android-observer/build.py
python3 android-observer/tests/build_device_checks.py
```

The build is offline and checks the pinned ZXing 3.5.3 JAR SHA-256. The second command builds a separate instrumentation APK in addition to rebuilding the release. Run instrumentation only on a disposable emulator: it writes synthetic enrollment and clears the target app's enrollment/queue.

```sh
adb -s emulator-5580 install -r android-observer/build/paykr-observer-2.0.0.apk
adb -s emulator-5580 install -r android-observer/build/device-checks.apk
adb -s emulator-5580 shell am instrument -w com.paykr.observer.checks/com.paykr.observer.DeviceChecks
```

Signing material is generated once under `.private/`, ignored by Git and protected by filesystem permissions. Back it up securely: losing it prevents signing updates for installed copies. Do not distribute this directory or its password. Build outputs are ignored; output hashes change on rebuild due to archive timestamps. The release is not debuggable.

ZXing source/dependency provenance: Maven Central `com.google.zxing:core:3.5.3`, SHA-256 `8d8064c1636fdaef7189dd9055c7d59950a8940a12f2293956446ec3c109fd82`. Apache 2.0 license and upstream notice: `libs/ZXING-LICENSE`; distribute that file with the APK. No Gradle plugins or remote build scripts execute.

See `evidence/REVIEW.md` for artifact findings, verification results and remaining limits. Rebuilding and static review do not constitute a security certification or establish production readiness.
