# Paykr → Laravel 12 migration pack

Everything needed to rebuild Paykr as a Laravel 12 + Filament v5 + React application, without
losing any route, method or service from the current Node.js code.

| File | What it is | Who reads it |
|---|---|---|
| [01-MIGRATION-SPEC.md](01-MIGRATION-SPEC.md) | The specification: architecture, multi-merchant model, MySQL schema, every domain rule, API, real-time, both Filament panels, React checkout, paykr.js, Android contract, sidecars, security, config, dropped items | The builder, start to finish |
| [02-BUILD-PROMPTS.md](02-BUILD-PROMPTS.md) | A master prompt plus 12 phase prompts (Phase 0–11), each with "Done when" criteria | Paste into the AI coding agent, one phase at a time |
| [03-ROUTE-MAP.md](03-ROUTE-MAP.md) | One row per current Payment API route (109) with its Laravel location, auth and spec section; plus the other services | Parity tracking |
| [04-ENGINEERING-STANDARDS.md](04-ENGINEERING-STANDARDS.md) | Mandatory code standards: FormRequest → DTO → Action/Service → Resource, thin controllers, Filament reusing the same Actions, one error format with an error-code catalog, test requirements, Pest architecture tests that enforce it all | The builder, every phase |
| [90-APPENDIX-INVENTORY.md](90-APPENDIX-INVENTORY.md) | Generated from the source code: all routes with file:line, classes and methods, audit actions, internal events, SSE broadcasts, environment variables, 249 existing test cases, current DB schema | Proof of completeness |

## How to use

1. Create the new Laravel repository.
2. Give the AI coding agent the **Master prompt** from `02-BUILD-PROMPTS.md`, with both repository paths filled in.
3. Run Phase 0, check its "Done when" list, then Phase 1, and so on. Don't skip ahead: later phases depend on the domain services and tests from earlier ones.
4. The agent keeps `docs/PARITY.md` in the new repo: one tick per route and per old test case. The migration is complete when it is fully ticked or every gap is listed under "Dropped / replaced" (spec §18).

## Regenerating the inventory

The appendix and route map are generated from the code. If the Node code changes before the migration finishes, regenerate them (scripts were used from a scratch folder; the logic is simple: parse `server.(get|post|delete)('...')` calls, `emit('X.Y')`, `broadcastSse`, `process.env.X`, `test('...')`, and the SQLite schema).

## Decisions recorded

Multi-merchant with one platform admin · Filament `/admin` (platform owner) and `/merchant` (each business) · MySQL 8 · WhatsApp OTP via Baileys Node sidecar · Google Messages observer as Node sidecar · Gmail moves into Laravel jobs · React for the customer checkout, plain-JS `paykr.js` for merchants.
