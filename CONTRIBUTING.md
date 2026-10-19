# Contributing to Paykr

We welcome bug fixes, documentation improvements, tests and integrations. For a large change,
open an issue explaining the problem before implementing it. Use synthetic payment data.

## Pull requests

1. Fork the repository and clone your fork.
2. Create a branch: `git switch -c fix/short-description`.
3. Follow the README setup and make a focused change.
4. Run relevant checks from the README. Payment/security changes need regression coverage.
5. Push your branch to your fork and open a PR targeting `piyushjaiswal7668/paykr:main`.
6. Explain the problem, behavior change and test results. Respond to review feedback.

Only the repository owner controls merging to `main`. Opening a PR does not grant push,
merge or deployment rights. Do not request write access just to contribute. Main is protected;
force pushes and deletion are blocked. CI must pass before a change is merged. Contributor
workflow runs may need owner approval. No automatic merge or deployment is granted to PRs.

Never include real merchant data, bank messages, OTPs, API keys, `.env` files, APK signing keys,
private captures or database dumps. Do not use production credentials in tests.
Report vulnerabilities privately using [SECURITY.md](SECURITY.md), not a public issue.

## Scope and standards

Keep existing tenant isolation, money validation, webhook signatures, replay protection and
idempotency intact. Browser callbacks and screenshots cannot authorize fulfilment. Do not label
SMS-based verification as direct bank confirmation. Document provider requirements and pricing
assumptions for any new bank integration; do not add undocumented scraping of private accounts.

Use Pint for PHP formatting, existing tests and existing dependencies where practical. Explain
schema changes and deployment steps. Update integration docs when changing public contracts.
By submitting a contribution, you agree to license your contribution under this repository's
MIT license and confirm you have the right to contribute it. Retain third-party notices.
