# Tested public asset releases

For one-click server deployment, use **Deploy production** after completing
[the one-time setup](PRODUCTION-DEPLOYMENT.md). It runs checks, builds and deploys in one
workflow. **Prepare release** below remains a build-only option for manual installations.

Use GitHub Actions → Prepare release → Run workflow → main. The workflow first runs the
existing checks on the exact dispatch commit. Only a passing run can build and publish
`assets-<full-commit-sha>` with the frontend and Filament assets and SHA256SUMS. No external
actions are used. The checks job has read-only permissions; only the main-only publish job
has contents-write permission. PRs do not publish releases or receive production secrets.

The publish job builds again from the same source commit and lockfiles. These are public
static assets, not a complete server image. PHP dependencies must be installed separately
from composer.lock. Never transfer x86 native Node modules to an ARM host. Each bundle
contains release-assets.json with its source SHA and individual file checksums. Existing
release tags are not overwritten: a repeated release request fails rather than replaces
an asset silently. Administrators can still modify GitHub releases; this is not cryptographic
provenance or GitHub-enforced immutable release storage.

## Oracle installation

1. Select a successful Prepare release run and its exact commit SHA.
2. Prepare a new source release at that SHA, with shared .env and storage links, and install
   locked production PHP dependencies. Do not run tests against production data.
3. Download paykr-assets-<sha>.tar.gz and SHA256SUMS from that release over HTTPS. Run
   `sha256sum --check SHA256SUMS` in the download directory before extraction.
4. Extract the trusted archive into that source release as the deployment user. Confirm
   release-assets.json names the exact source SHA. The archive includes only public/build,
   public/sdk, published Filament assets and release-assets.json. It contains no credentials,
   storage, database, node_modules or vendor directory.
5. In the shared production environment set `HORIZON_MAX_PROCESSES=1` for the initial small
   host. Production defaults to one; raising this requires memory/latency measurements.
   This is only the Horizon worker count, not a limit on total PHP-FPM/Reverb/sidecar memory.
6. Follow docs/CUTOVER.md for backups, migrations, first admin seeding, production checks,
   PHP-FPM and worker supervision, and release activation. A bundle alone does not deploy
   or start the application. Nginx must serve current/public and keep internal endpoints private.

Do not use bare git pull inside a live release. Keep previous source/assets paired for code
rollback. A database migration may prevent code rollback; never automatically restore an
old payment database. First production payment/provider acceptance remains a separate gate.

A 1 GB server is suitable only for a constrained trial pending load and memory measurements.
Builds and tests belong on CI. PHP-FPM, MySQL and optional sidecars also need memory budgeting.
