# Artifact review and validation — 2026-10-18

## Inspected shipped APK

Original preserved at `storage/app/private/releases/paykr-sms-gateway.apk`.
SHA-256: `aa057e5e3c2ae60a48d66d846c960272bc5b65eef8c3cce16f5953808a97405e`.
REA package evidence: `ev_f1b241458baa469036d8b1dc32869236be51e988c4968aeadd94f6341c1560fa`.
Observed identity: `com.youpi.observer`, version 1.0.0/code 1, minimum API 26, target 34.

Static method inspection found ordinary preference storage of the signing key, unsigned heartbeats, configurable HTTP destinations, and SMS event time reused as the authentication timestamp. Exact method evidence is retained in `apk_*.json`. Decompiled code has coverage limits and is not execution evidence. The old artifact was not launched. Available Node-project Android source was used as a functional reference, not assumed identical to the shipped APK.

## New artifact

`android-observer/build/paykr-observer-2.0.0.apk`
SHA-256: `e4f45cd41159ed06a791a5c2fe22e368917391aae776da99e20da11475886bae`.
REA evidence: `ev_0a70d3dc932510a4b81882da114742501606f49067e2f12c9280dff8fb3eda42`, retained in `new_apk_evidence.json`.
Observed package `com.paykr.observer`, version 2.0.0/code 2, minimum API 26, target 35, seven declared permissions. No READ_SMS, WAKE_LOCK or battery-optimization exemption request.

Android build-tools 35 apksigner independently verified v2 and v3 signatures. Signer certificate SHA-256: `f3f5c433132bd29e84b43e60903f2aa6557b3b78568058bcae061073f6a40c2b`. This proves signature integrity, not publisher trust or application security. REA itself does not verify signatures.

## Executed checks

- JDK protocol tests passed: HTTPS URL boundaries, sender/OTP/payment filters, harmless diagnostic text and fixed UTF-8 HMAC vector matching the Laravel signing contract.
- Signed release APK and separate instrumentation APK installed successfully in Pixel_8a read-only emulator (Android API 37 system image).
- Release MainActivity cold launch passed (`Status: ok`, 1,273 ms).
- Android instrumentation passed: Keystore encryption/decryption; encrypted queue payload; stale enrollment rejection; duplicate suppression; original event timestamp; stopped capture; local unpair clears enrollment and queue.
- Independent code review identified and fixed network work under the queue monitor, pre-enqueue merchant identity races, retention during drain, hidden rejected counts, service scheduler exceptions, Stop scheduling order, and null notification fields.

## Deliberate scope and unresolved acceptance

No live merchant phone pairing, HTTPS Laravel delivery, bank SMS verification, QR camera scan, notification permission flow, offline recovery, boot recovery or OEM battery behavior has been demonstrated by these tests. Emulator storage checks are stronger than source-only assertions but do not establish full operational behavior. The signed payload algorithm was checked against a fixed contract vector; this is not a live server acceptance test.

New code preserves core observation/pairing behavior while removing unsafe compatibility: HTTP, fabricated payment test messages, automatic broad notification collection and unsigned health requests. It is not a byte-for-byte clone. Notification forwarding preserves actual package identity and cannot be claimed to verify payments until Laravel's bank matching is exercised. HMAC keys are encrypted at rest, but necessarily available to the app process while signing. Rooted/compromised phones are outside this protection. Local unpair does not invalidate the server's issued device credential.

## Live phone transport acceptance — 2026-10-18

The signed release installed via authorized USB developer installation on the user's iQOO 13 (Android model I2401), with Play Protect settings unchanged. WhatsApp/file installation had been blocked by Play Protect's sensitive-permissions protection. MainActivity launched successfully on the phone.

A user-approved temporary Cloudflare HTTPS tunnel forwards only the Android pair/events/heartbeat paths to a loopback Laravel test entry point, with debug output disabled. Public health succeeded; private file paths returned 404; invalid pairing returned 422. The user scanned a short-lived enrollment QR and confirmed pairing. Laravel recorded device enrollment at 11:32:12 UTC, a subsequent heartbeat at 11:35:45 UTC and an accepted observation at 11:35:46 UTC (17:05:46 IST). The user reported sending the requested `PAYKR OBSERVER TEST phone-check` SMS. No payment observation was created since pairing.

This verifies live phone enrollment, heartbeat and signed event transport. Non-bank SMS bodies are not retained by the intake path, so exact message content is user-reported rather than independently recovered from storage. Real bank-credit extraction/payment matching, offline retry and reboot/background endurance remain pending. Temporary tunnel availability is limited to this running test session; it is not a production deployment.

## Real bank SMS acceptance — 2026-10-18

After the user corrected the receiving UPI address, the earlier payment request was cancelled and a replacement ₹1 session created through PaymentApiService and SlotQueueService. The user reported receiving the bank SMS. Laravel recorded an SMS_GATEWAY observation for 100 paise with bank reference ending 0448, observed at 17:17:33 IST and accepted at approximately 17:17:59 IST, before the 17:19:31 expiry. The observation was attributed to the replacement payment with verification level 1.

The first status read still showed ACTIVE with attributed evidence. The existing guarded AttributionEngine::finalizeLevel1 method was invoked specifically for this payment; the subsequent status was SUCCESS, completed at 17:18:42 IST. No status field was manually overwritten and no signature, parser, amount or deadline check was bypassed. This demonstrates real SMS capture, extraction, matching and successful normal domain finalization, but does not establish unattended queue/scheduler operation; that runtime check remains outstanding. Delayed SMS arriving beyond the confirmation window remains a separate acceptance case.

## Automatic finalization runtime — 2026-10-18

No queue worker, payment clock or scheduler was running when the live payment required intervention. Started the local payments-only Redis worker, payment clock and Laravel scheduler. The worker consumed the previously queued deadline/finalization jobs successfully; terminal payment transitions remained idempotent.

`tests/Support/check-background-finalization.php` passed all three independent-process scenarios using synthetic observations in an isolated temporary SQLite database and unique Redis namespace: delayed Redis job finalization, payment-clock recovery when the initial finalization job is suppressed, and scheduled verification recovery followed by worker execution. Each remained ACTIVE until the grace deadline, transitioned to SUCCESS automatically and released its receiving account slot. No additional real payment was needed. Temporary test data and child processes were cleaned up. Local live processes remain running for subsequent phone testing, but are not installed as reboot-persistent services. Webhook delivery is not covered by this payments-only worker.

## Delayed SMS characterization — 2026-10-18

Ran `tests/Feature/Verification/DelayedAndroidSmsTest.php` through the signed Android HTTP endpoint using in-memory SQLite, synthetic fixtures, fake queued jobs and blocked outbound HTTP. Four characterization cases passed (30 assertions); passing means the observed behavior is reproduced, NOT that all safety requirements pass.

| Scenario | Observed result |
| --- | --- |
| Bank SMS timestamp after expiry, no newer payment | NO_ACTIVE_SESSION; expired order unchanged; no observation or reconciliation ticket retained |
| Offline delivery after expiry with original pre-expiry timestamp | NO_ACTIVE_SESSION; expired order unchanged; no observation or ticket retained |
| Original pre-expiry SMS timestamp while newer same-amount order active | BEFORE_SESSION_CREATION; newer order stays ACTIVE; no observation or ticket retained |
| Bank-generated delayed SMS has timestamp inside newer same-amount order window | VERIFIED; newer order becomes SUCCESS — unsafe ambiguous attribution reproduced |

The last case models a genuinely late-generated bank SMS whose amount matches a newer order. Its fresh 12-digit reference is not independently bound to either order, so reference presence, amount and SMS timestamp are insufficient to resolve the ambiguity. The Android flow has no expected order identity in this evidence. The verification pipeline returns before the attribution engine's existing late-reconciliation path in the rejected cases. No live payment or receiving-account data was changed by these tests.

Late-payment acceptance is NOT complete. Before more live testing, implement conservative ambiguity handling and retained reconciliation evidence; an independent transaction-to-order binding is needed for confidently assigning ambiguous credits. Do not interpret longer timeouts or changing a UPI alias as a fix for ambiguous matching. The characterization explicitly documents the unsafe current behavior and should be updated to a rejection/reconciliation invariant when fixed.

## Delayed-credit fix — 2026-10-18

The characterization above is superseded by regression tests. Parsed, non-customer bank credits outside a live payment window are now persisted with LATE_UNMATCHED status, no attributed order, their original payload/reference/timestamp and a reviewReason. CREDIT_REVIEW_REQUIRED audit events record the reason. The Android response reports NEEDS_REVIEW and the retained observation ID, so acknowledged messages can leave the phone queue without losing reconciliation evidence. Invalid signatures, invalid sender/parser inputs and customer-spoofed evidence are not admitted through this path.

When an active order could compete with an older expired/failed/cancelled same-amount UPI order on the same receiving account, automatic attribution is held. The ambiguity guard deliberately has no time cutoff: the existing 30-minute reconciliation lookback does not prove a later bank message belongs to the new order. This can hold legitimate repeated same-amount payments for manual review. It does not establish independent bank confirmation or provide automatic refunds/manual settlement UI. A trusted transaction-to-order binding is still needed to resolve ambiguity; neither a new UTR alone nor the caller's supplied payment ID overrides the guard.

Freshly signed retries do not duplicate retained credits. Cross-channel copies of held evidence remain held, and the reattribution worker cannot promote review records. Current-time deadline checks also prevent a stopped clock from allowing an offline observation to revive an overdue active session. Existing fraud, tenant, replay, duplicate, ordinary attribution and late-ticket tests remain covered. No Android rebuild or database migration is required.

## Merchant approval dashboard — 2026-10-18

Added Credit Reviews in the merchant panel for owners/managers. After the user explicitly chose reopening closed orders, implemented a separate guarded reviewed-credit settlement path: automatic transition rules continue to reject reopening, while a merchant approval must persist its reviewed evidence and explicit reopening confirmation before EXPIRED/CANCELLED/FAILED can transition to SUCCESS. A receiving-account lock serializes manual approvals with automatic attribution; another payment's active slot is preserved. Approvals validate tenant, account, amount and exact bank reference, prevent consumed-reference reuse, and are idempotent with an approver/note/timestamp audit trail. The normal success webhook event is recorded.

Filament Livewire tests exercise the actual approval action. Regression cases cover live approval, all three closed states, missing reopening consent, an occupied newer slot, unauthorized roles, cross-tenant order substitution, wrong/reused references, and direct attempts to bypass the guarded transition. No real held credit was approved as part of implementation testing.
