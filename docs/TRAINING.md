# Paykr training video library

Merchant dashboard: **Help → Training** (`/merchant/{store-slug}/training`).
Admin dashboard: **Training** (`/admin/training`). Open an individual lesson to play it;
English captions are burned into each MP4. Native controls support seeking and full screen.

The library is read from `storage/app/private/training/catalog.json`. Entries contain
`audience` (`merchant`, `admin`, `integration`), a two-digit `id`, and a display `title`.
Each video lives at `storage/app/private/training/{audience}/{id}.mp4`. Only existing files
are listed. Deploy this curated directory alongside application code; do not place it in
`public/` or expose all private storage through nginx. Demo screenshots, scripts and audio
sources in the sibling onboarding directories are not required by the deployed application.

Merchant users need active store membership. Admin-only videos require the admin session,
platform-admin status, enrolled authenticator, and a signed URL generated on the protected
panel. Links expire after two hours; refresh the Training page to obtain fresh links.
Unsigned/expired links and unauthorized users are rejected. Symfony file responses support
byte ranges for seeking. Downloaded copies can be forwarded outside these access controls.

For offline sharing, use the named files in each onboarding `sections/` directory or its
chapter ZIP. The integration guide covers browser SDK use, backend order creation, raw-body
webhook verification, atomic fulfilment, tests, dashboard playback, and private media deployment.
Code screens are illustrative integration excerpts, not a replacement for the merchant's
order database, framework routing and production deployment configuration.

Verification: `tests/Feature/TrainingTest.php` exercises signed access, membership, suspended
merchants, admin-only media, expiry, missing files and partial-content responses. Isolated
browser capture verifies playback and absence of admin lessons in the merchant library.
