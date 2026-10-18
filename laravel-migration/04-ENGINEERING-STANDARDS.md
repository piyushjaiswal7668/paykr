# Engineering standards (mandatory for every phase)

> **Audience:** the builder of the Laravel version. These rules apply to every file. They are enforced by
> Pest architecture tests (§8), so a phase is not "done" while any of them fail.

The goal: **business logic is written once** (in an Action/Service) and reused by the API, Filament
pages, jobs, console commands and sidecar callbacks. Controllers and Filament pages only translate
input/output.

---

## 1. Layers and what each may do

```
HTTP request ─▶ FormRequest ─▶ Controller ─▶ DTO ─▶ Action / Service ─▶ Models (Eloquent) ─▶ DB
                                   │                      │
Filament page/action ─▶ Form ─▶ DTO ┘                     ├─▶ Domain events (after commit) ─▶ Listeners/Jobs
Job / Command / Listener ─▶ DTO ──────────────────────────┘
                                   ◀── Result DTO ◀───────┘
Controller ─▶ API Resource (response)        Filament ─▶ Notification / table refresh
```

| Layer | Location | Allowed | Not allowed |
|---|---|---|---|
| **FormRequest** | `app/Http/Requests/{Area}/…Request.php` | Validation rules, `authorize()` (policy check), `toDto()` returning the DTO | Business logic, DB writes |
| **Controller** | `app/Http/Controllers/Api/V1/…` | Call `$request->toDto()`, call **one** Action/Service method, return a Resource or `ApiResponse` | Eloquent queries, `DB::`, `if` on business rules, try/catch of domain exceptions (the handler does it). **Max ~15 lines per method.** Prefer single-action invokable controllers. |
| **DTO** | `app/Domain/{Context}/Data/…Data.php` | `final readonly class` with typed promoted properties; `fromRequest()`, `fromArray()`, `fromFilament(array $state)` named constructors; value objects for money (`Money`), mobile (`MobileNumber`), VPA (`Vpa`) | Behaviour beyond normalization, Eloquent models as properties (pass ids) |
| **Action** | `app/Domain/{Context}/Actions/…Action.php` | One use case, one public `handle(Dto): ResultDto` (or `__invoke`). Owns the DB transaction. Throws `DomainException` subclasses. Dispatches domain events. | Reading `request()`, `auth()` (actor is passed in the DTO), returning HTTP responses, Filament notifications |
| **Service** | `app/Domain/{Context}/Services/…Service.php` | Reusable domain logic shared by several Actions (e.g. `PaymentTransitionService`, `SlotQueueService`, `VerificationPipeline`, `AttributionEngine`, `WebhookService`) | HTTP concerns |
| **Query** | `app/Domain/{Context}/Queries/…Query.php` | Read models for tables/dashboards (e.g. `LiveMonitorQuery`, `CounterPendingQuery`), tenant-scoped, return DTOs/collections | Writes |
| **Model** | `app/Models` | Relations, casts, scopes, tenant global scope | Business workflows |
| **API Resource** | `app/Http/Resources/V1/…Resource.php` | Explicit field allow-list, formatting (paise → rupees string) | Queries (eager-load in the Action/Query) |
| **Filament page/resource/action** | `app/Filament/{Admin,Merchant}/…` | Build form/table, map form state → DTO, call the **same** Action/Query, show notification | Direct `Model::create/update/delete` for domain entities, business `if`s |
| **Job / Listener / Command** | `app/Jobs`, `app/Listeners`, `app/Console/Commands` | Build DTO, call Action/Service | Business logic |

Contexts (`app/Domain/<Context>`): `Payments`, `Checkout`, `Customers`, `Devices`, `Observations`, `Verification`, `Webhooks`, `Merchants`, `ReceivingAccounts`, `Security`, `Support`, `Observers` (Android/Gmail/sidecars), `Platform`, `Shared` (Money, value objects, errors).

### 1.1 Reuse examples (one implementation, many callers)

| Use case | Single implementation | Called from |
|---|---|---|
| Confirm counter payment | `Payments\Actions\ConfirmCounterPaymentAction` | Merchant ▸ Counter desk action, `/api/v1/merchant/counter/{id}/confirm` (if exposed), tests |
| Cancel payment | `Payments\Actions\CancelPaymentAction` | Checkout API `cancel`, `end-and-restart`, `retry-new-payment`, platform admin "Force close", ban flow |
| Clear waiting queue | `Payments\Actions\ClearMerchantQueueAction` | Merchant Live monitor action, platform admin merchant page |
| Ban / unban customer | `Security\Actions\BanCustomerAction`, `UnbanCustomerAction` | Platform admin pages, verification pipeline (auto-ban), tests |
| Inject simulated observation | `Observations\Actions\InjectTestObservationAction` | Merchant Payment simulator, `simulate-observation` API, `test-mode/inject` |
| Live monitor numbers | `Payments\Queries\LiveMonitorQuery` | Merchant dashboard widgets, platform dashboard (per merchant), `/health` summary |
| Create payment | `Payments\Actions\CreatePaymentAction` | `POST /api/v1/payments`, `end-and-restart` (new session), merchant panel "Create test payment" |
| Pair Android device | `Observers\Actions\PairAndroidDeviceAction` | Pair API; `IssuePairingTokenAction` used by the Filament QR page |

---

## 2. DTO conventions

```php
final readonly class CreatePaymentData
{
    public function __construct(
        public string $merchantId,
        public string $orderId,
        public Money $amount,                 // value object holding int paise
        public PaymentMethod $method,
        public ?CustomerHintData $customer,
        public array $metadata,
        public ?string $returnUrl,
        public ?string $deviceId,
        public ?string $idempotencyKey,
        public Actor $actor,                  // who is doing this (merchant key, user, customer, system)
    ) {}

    public static function fromRequest(CreatePaymentRequest $r, Actor $actor): self { /* … */ }
}
```

- Every Action input and every non-trivial output is a DTO. Result DTOs end in `Result` (`CreatePaymentResult`).
- `Actor` value object: `type` (PLATFORM_ADMIN, MERCHANT_USER, MERCHANT_API_KEY, CUSTOMER, DEVICE, SIDECAR, SYSTEM), `id`, `merchantId`, `ip`. Audit logs are written from it, so the real actor is always recorded.
- Use `spatie/laravel-data` **or** plain readonly classes; pick one in Phase 0 and use it everywhere.

---

## 3. Universal error format and error codes

### 3.1 Exceptions
`app/Domain/Shared/Errors/PaykrException` (abstract) carries an `ErrorCode` enum case, a safe message, HTTP status and optional `details` array. Subclasses per family (`PaymentException`, `CheckoutException`, `AuthException`, `ObserverException`, …). Actions throw them; nobody catches them in controllers.

`bootstrap/app.php` → `withExceptions()` renders **every** API error (domain, validation, auth, 404, throttling, unexpected) through one renderer.

### 3.2 Response shape

One renderer, two variants: the **legacy `/api/v1` contract** (the Android app, `paykr.js` and merchant SDKs read `error` as a string today) and the **standard** shape for new APIs.

**Legacy `/api/v1/*` routes** (everything listed as "Same path" in `03-ROUTE-MAP.md`):

```json
{
  "success": false,
  "error": "This payment has already ended and cannot be changed.",
  "code": "PAYMENT_CLOSED",
  "message": "This payment has already ended and cannot be changed.",
  "details": { "status": "CANCELLED" },
  "fields": { "amount": ["The amount must be greater than 0."] },
  "request_id": "01JB2…"
}
```
`error` stays a string; `code`, `message`, `details`, `fields`, `request_id` are added. Any extra keys the old endpoint returned (`action`, `session_id`, `conflicting_session_id`, `boundDeviceId`, `requestingDeviceId`, `isBanned`, `status`, `reason`, `blockedNotice`, `threatDetected`, `banned`, `testModeActive` …) are kept at top level via the exception's `legacy()` array. Contract tests pin them per endpoint.

**New APIs** (`/api/v1/merchant/*`, `/internal/*`):

```json
{
  "success": false,
  "error": { "code": "PAYMENT_CLOSED", "message": "…", "details": {}, "fields": {} },
  "request_id": "01JB2…"
}
```
Success: `{ "success": true, "data": …, "meta": … }`.

- `fields` only for validation errors. `request_id` = `X-Request-Id` header (generated by middleware, logged with every log line).
- Success responses on legacy `/api/v1` routes keep their current shapes exactly (public contract).
- Never leak exception class names, SQL, stack traces or secrets. Unexpected errors → `INTERNAL_ERROR` with the request id.
- Filament shows the same `message` in a danger notification (one helper: `NotifiesPaykrErrors::run(fn () => $action->handle($dto))`).

### 3.3 Error code catalog (`App\Domain\Shared\Errors\ErrorCode` enum)

| Code | HTTP | Used for (from the current code) |
|---|---|---|
| `VALIDATION_FAILED` | 422 | any FormRequest failure |
| `UNAUTHENTICATED` | 401 | missing/invalid session, key or token |
| `ADMIN_AUTH_REQUIRED` | 401 | admin/internal endpoint without auth (kept from current code) |
| `FORBIDDEN` | 403 | policy/role denial |
| `NOT_FOUND` | 404 | generic |
| `RATE_LIMITED` | 429 | throttles (OTP, login, payments) |
| `INTERNAL_ERROR` | 500 | unexpected |
| `SERVICE_UNAVAILABLE` | 503 | sidecar down, admin login not configured |
| `MERCHANT_NOT_FOUND` / `MERCHANT_SUSPENDED` | 404 / 403 | payment creation |
| `INVALID_API_KEY` / `ORIGIN_NOT_ALLOWED` | 401 / 403 | merchant keys |
| `PAYMENT_NOT_FOUND` | 404 | payments, checkout |
| `ORDER_NOT_FOUND` | 404 | `/orders/{orderId}/cod` |
| `INVALID_AMOUNT` | 422 | `toPaise` failures |
| `NO_ACTIVE_RECEIVING_ACCOUNT` | 422 | UPI payment without active VPA |
| `INVALID_VPA` | 422 | receiving accounts |
| `RECEIVING_ACCOUNT_BUSY` | 409 | delete while slot busy |
| `PAYMENT_CLOSED` | 409 | any change to a terminal session (cancel/confirm/COD/decline/select-method) |
| `INVALID_STATE_TRANSITION` | 409 | e.g. payment-done when not ACTIVE |
| `PAYMENT_EXPIRED` | 409 | payment-done after expiry |
| `PAYMENT_METHOD_DISABLED` / `COD_NOT_AVAILABLE` | 422 | select-method, confirm-cod |
| `DUPLICATE_SESSION_DETECTED` | 409 | device/customer conflict (`action: END_SESSION_AND_CREATE_NEW`) |
| `DEVICE_SESSION_MISMATCH` | 409 | `/payments` same order on another device |
| `DEVICE_REQUIRED` / `DEVICE_REVOKED` / `DEVICE_SIGNATURE_INVALID` | 400 / 409 / 409 | device binding |
| `SESSION_ALREADY_BOUND` | 409 | bind-customer to another customer |
| `CUSTOMER_BANNED` | 403 | legacy keys `status: BLOCKED`, `isBanned: true` |
| `CUSTOMER_BLOCKED_BY_MERCHANT` | 403 | merchant block |
| `INVALID_MOBILE` / `INVALID_EMAIL` | 422 | customer auth |
| `EMAIL_TAKEN` / `MOBILE_TAKEN` | 409 | signup/register |
| `OTP_INVALID` / `OTP_EXPIRED` / `OTP_NOT_REQUESTED` / `OTP_ATTEMPTS_EXCEEDED` | 400/400/400/429 | verify-otp |
| `INVALID_CREDENTIALS` | 401 | password login |
| `CUSTOMER_SESSION_EXPIRED` | 401 | `me`, switch-account |
| `PAIRING_TOKEN_INVALID` / `PAIRING_QR_EXPIRED` / `PAIRING_QR_INVALID` | 401 / 400 / 400 | Android pairing |
| `DEVICE_NOT_AUTHORIZED` | 403 | observer device inactive |
| `SIGNATURE_MISSING` / `SIGNATURE_INVALID` / `TIMESTAMP_DRIFT` | 401 | observer HMAC |
| `TEST_MODE_INACTIVE` | 403 | simulator/test inject |
| `SESSION_NOT_ACTIVE` | 400 | simulator target |
| `GMAIL_NOT_CONFIGURED` / `GMAIL_OAUTH_FAILED` | 422 / 400 | Gmail |
| `WHATSAPP_NOT_CONNECTED` | 503 | WhatsApp sidecar |
| `WEBHOOK_URL_NOT_ALLOWED` | 422 | SSRF guard |
| `FRAUDULENT_PAYMENT_INJECTION` | 403 | pipeline fraud (legacy keys `threatDetected`, `banned`) |

Verification-pipeline **outcome** reasons are not HTTP errors; they stay as `failingCondition` values in the pipeline result: `PERSONAL_SMS_REJECTED, INVALID_SMS_SENDER, INVALID_EMAIL_DOMAIN, IRRELEVANT_SOURCE, MISSING_CREDIT_INDICATOR, MISSING_CURRENCY_INDICATOR, MISSING_12_DIGIT_REFERENCE, INVALID_OR_MISSING_AMOUNT, NO_ACTIVE_SESSION, BEFORE_SESSION_CREATION, SESSION_EXPIRED, AMOUNT_MISMATCH, DUPLICATE_OBSERVATION, REFERENCE_ALREADY_USED, BANNED_IDENTITY, FRAUDULENT_PAYMENT_INJECTION` (enum `PipelineFailure`).

Messages live in `lang/en/errors.php` keyed by code (white-label friendly, translatable).

---

## 4. Request / response classes

- One FormRequest per endpoint and per Filament form that is also reachable by API (share rules via `App\Domain\…\Rules` classes so the Filament form and the API validate identically).
- One API Resource per response shape. Contract tests (`tests/Contract/*`) snapshot the JSON keys of every `/api/v1` response against the shapes documented in spec §5 and `AGENTS.md`.
- Pagination via `meta` (`page`, `per_page`, `total`) on new APIs only.

---

## 5. Filament rules

- Resources for plain CRUD config (e.g. team members) may use Filament's built-in create/edit **only** if the model has no domain rules. Anything with rules (receiving accounts activation, branding that affects checkout, payment methods, API keys, bans, counter decisions, queue, test mode, pairing, Gmail) goes through an Action via `->action(fn (array $data) => app(XAction::class)->handle(XData::fromFilament($data, Actor::fromUser())))`.
- Tables read through Query classes (or Eloquent scopes defined on the model), never ad-hoc joins in the page.
- Authorization through policies shared with the API (`MerchantPolicy`, `PaymentPolicy`, …) using the role matrix in spec §2.1.

---

## 6. Testing standards

| Kind | Folder | What |
|---|---|---|
| Unit | `tests/Unit/Domain/**` | Money, validators, parsers (datasets from the old tests), fingerprint, pipeline stages, attribution, transition service, queue service, webhook signing |
| Feature (API) | `tests/Feature/Api/**` | Every `/api/v1` route: happy path, auth failure, validation failure, each documented error code, tenant isolation |
| Feature (Filament) | `tests/Feature/Filament/**` | Every page renders for allowed roles, denied for others; every action calls the Action and shows the right notification (Livewire testing helpers) |
| Contract | `tests/Contract/**` | JSON shape of every `/api/v1` response and the webhook payload/headers; Android app request replay (`driver.js`) |
| Architecture | `tests/Arch/**` | §8 rules |
| Concurrency | `tests/Concurrency/**` | 50 parallel slot acquisitions on MySQL; no double activation |
| E2E | `tests/Browser/**` (Playwright or Dusk) | Flows listed in spec §20.4 |

- Every Action has at least one unit/feature test per outcome (success + each exception it can throw).
- Every **FIX** in the spec has a regression test named `fix_<short_name>`.
- Old test names from Appendix A8 are used as Pest `it()` descriptions so `PARITY.md` can be checked by grep.
- Coverage gate: ≥ 90 % lines on `app/Domain`, ≥ 80 % overall (`pest --coverage --min=80`).
- Factories + states for every model (`PaymentSession::factory()->active()->forAccount($acc)`); no seeded fixtures in tests.

---

## 7. Other conventions
- `declare(strict_types=1);` everywhere; PHPStan/Larastan level 8 on `app/Domain`, 6 elsewhere; Pint (Laravel preset).
- Enums for every status/type; no string literals for statuses outside enums.
- Config in `config/paykr.php`; no `env()` outside config files.
- Transactions only inside Actions/Services; domain events dispatched `afterCommit`.
- Logs are structured (`Log::withContext(['request_id', 'merchant_id', 'payment_id'])`); never log OTPs, tokens, secrets or full raw SMS outside `security_events`.
- Money formatting only in Resources/views (`Money::toRupeesString()`).

---

## 8. Architecture tests (Pest `arch()`), must pass in every phase

```php
arch('controllers stay thin')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Eloquent\Builder'])
    ->and('App\Http\Controllers')->not->toUse('App\Models');   // models only via Actions/Queries

arch('filament uses actions, not models, for writes')
    ->expect('App\Filament')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('domain is framework-light')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate\Http\Request', 'Filament', 'Livewire']);

arch('dtos are immutable')
    ->expect('App\Domain\*\Data')->toBeFinal()->toBeReadonly();

arch('actions have a single entry point')
    ->expect('App\Domain\*\Actions')->toHaveMethod('handle');

arch('no env() outside config')->expect('App')->not->toUse('env');

arch('errors go through the catalog')
    ->expect('App\Domain')->not->toUse(['Symfony\Component\HttpKernel\Exception\HttpException']);
```

Plus custom tests: every merchant-owned model uses `BelongsToMerchant`; every `/api/v1` route has a FormRequest (except GET without input) and returns a Resource or `ApiResponse`; controller methods ≤ 15 lines (static check via reflection/line count).
