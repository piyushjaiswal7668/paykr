# Third-party notices

The root MIT license covers project-owned source. It does not relicense dependencies,
third-party logos/trademarks, or legacy compiled artifacts.

- PHP and JavaScript dependency versions are recorded in composer.lock and package-lock.json
  (including service lockfiles). Preserve their individual upstream licenses when distributing.
- Android bundles ZXing core 3.5.3 under Apache-2.0. Retain
  android-observer/libs/ZXING-LICENSE and the provenance in android-observer/README.md.
- Payment-app, bank and card-network icons under public/icons identify supported apps/methods.
  Their respective owners retain trademark and other rights; inclusion is not endorsement.
- The historical APK under storage/app/private/releases is a legacy compatibility artifact,
  not the current Android source release or a grant of third-party redistribution rights.
  Build the current observer from android-observer/ for your deployment.

Before redistributing a branded or compiled release, review the applicable upstream notices.
