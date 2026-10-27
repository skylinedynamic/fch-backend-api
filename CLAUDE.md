# fch-api

Laravel 12 API — the base project of the FCH platform (Passport auth, Spatie roles/permissions, Stripe, S3). Consumed by the borrower portal, the admin panel and the public website; routes are split by consumer under `routes/{admin,borrower,common,filemanager,website}.php`.

## Commands
- `php artisan test` — whole suite; `php artisan test --testsuite=Unit|Integration|Feature` for one suite
- `php artisan test tests/Path/To/SomeTest.php` / `--filter=method_name` — one file or test

## Tests
Three suites, defined in `phpunit.xml`, all run in CI (`.github/workflows/ci.yml`) on every PR to `stage`/`release`:

| Suite | What it covers | Database |
|---|---|---|
| `tests/Unit` | One class in isolation (helpers, pure calculations, value objects) | **None** — a test that needs the database is an Integration test |
| `tests/Integration` | Services, jobs, console commands, the schedule, mailables, events — called directly, real components wired together, nothing under test mocked | SQLite in-memory (`RefreshDatabase`) |
| `tests/Feature` | A real HTTP request through route → middleware → controller → resource | SQLite in-memory (`RefreshDatabase`) |

### Every change updates the tests (mandatory)
For **any** change — a new feature, a change to an existing feature, or a bug fix:

1. **Find the existing tests first.** Search `tests/Unit`, `tests/Integration` and `tests/Feature` for the class, route and behaviour you are touching. Update every test whose expectation the change affects — never leave a test asserting the old behaviour, and never delete or weaken a test to make it pass.
2. **Cover each level the change touches**, not just one:
   - **Unit** — new/changed pure logic (a calculation, a helper, a rule).
   - **Integration** — new/changed service, job, command, scheduled task, mailable or event.
   - **Feature** — new/changed route, request validation, permission/tenant access, or response shape.
3. **Bug fixes are test-first:** write the test expecting the *correct* behaviour, watch it fail, then fix. Give the test a `Defect:` docblock saying what was wrong.
4. **New features:** failing test → minimum code → refactor with the test green.
5. Run the affected suites before finishing, and say so (with the failure output if anything fails).

### Test conventions
- PHPUnit attributes: `#[Test]`, `#[CoversClass]`, `#[DataProvider]`; test names are sentences (`a_partner_cannot_see_another_partners_borrower`).
- Users/tenants via `Tests\Support\InteractsWithPortalUsers` (`makeHostPartner`, `makeWhiteLabelPartner`, `makePartnerAdmin`, `makeBorrowerUser`, `actingAsUser`, ...).
- External services are always faked (`Http::fake`, `Mail::fake`, `Notification::fake`, `Process::fake`, `Storage::fake`) — a test never calls Stripe, OpenAI, MicroBilt, Microsoft or Twilio.
- **Never touch real storage.** A test that writes or deletes files points storage at a temp folder and removes it in `tearDown`:
  ```php
  $this->storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
  // ensure app/public, framework/{views,cache/data,sessions}, logs exist; copy storage/oauth-*.key in
  $this->app->useStoragePath($this->storage);
  ```
  (plus `$this->app->usePublicPath(...)` / `Storage::fake('public')` where public files are involved).
- **Never run tests, migrations or tinker writes against the `.env` database.** Tests use the SQLite in-memory database pinned in `phpunit.xml`.
- SQLite cannot run MySQL-only SQL (`DATE_FORMAT`, `DATEDIFF`, `GROUP_CONCAT`, ...); note it in the test when a path can't be covered for that reason.

## Findings and handoffs
- **`docs/test-findings.md`** — running list of issues: Fixed (`F#`), Decisions needed (`D#`), Open (`O#`). Add anything found but not fixed; don't silently change behaviour that needs a product/compliance decision — record it as a `D#`.
- **`docs/handoff/`** — one file per piece of work, written when it is finished (see `docs/handoff/README.md`). Name: `YYYY-MM-DD-short-title.md`. It records **the problem**, **the solution**, the files changed, the tests added/updated and anything left open, so the next person (or session) can pick up without re-reading the history.

## Branching
`master` (default), `stage` (QA), `release` (production). Feature branches from `stage`; hotfixes from `release`, merged to both. Commit only when asked.
**This is the base project of the FCH platform.** It is the system of record and the shared backend for all three frontends — the data model, API contracts, auth/permissions, business logic and compliance controls all originate here. Cross-cutting decisions (schema changes, endpoint shape, roles/permissions, encryption, tenancy) are made in this repo first; the frontends follow. When a change spans repos, start here and treat the frontends as consumers.

Laravel 12 (PHP 8.2) API backend. Passport auth, Spatie permissions/activitylog/backup/health, Stripe, AWS S3, Octane, Twilio, 2FA (google2fa).

## Commands
- `composer test` — clears config, runs `php artisan test`
- `php artisan serve` — dev server
- `composer dev` — server + queue listener + pail logs + vite, concurrently
- `composer setup` — first-time project bootstrap: install, copy `.env`, `key:generate`, `migrate --force`, npm install/build
- `./vendor/bin/pint` — format PHP to Laravel Pint defaults (PSR-12); run before committing

## Architecture
- **Routing**: `routes/{admin,borrower,common,filemanager,website}.php`, each registered explicitly in `bootstrap/app.php` (`withRouting`) and prefixed by consumer (`v1/admin`, `v1/borrower`, …). Route groups apply `auth:api` plus a role-gate middleware (`admin`/`borrower`/`partner`). Spatie's `role`/`permission`/`role_or_permission` middleware aliases are registered but rarely applied at the route level today — most authorization is coarse role gating, not per-permission; use `->can()`/a policy when an action genuinely needs finer control instead of hand-rolled checks in the service.
- **Controllers**: `app/Http/Controllers/Api/V1/<Consumer>/<Feature>/<Name>Controller.php`, where `<Consumer>` is `Borrower`, `FCH` (internal/admin), `Global`, or `GlobalPayment`. Controllers are thin: inject exactly one `<Feature>Service`, delegate all logic to it, wrap in try/catch, and respond via `App\Helpers\ApiResponse` (`success`, `error`, `successWithPagination`, `validationError`, etc.) — never build a raw `response()->json()` in a controller. `DashboardController` is the canonical shape. This rule is not yet true of the existing codebase — 13+ controllers still call `response()->json()` directly (e.g. `Api/V1/Borrower/Funding/FundingRequestController.php`, `Api/V1/FCH/Common/Contact/ContactController.php`, `Api/V1/Global/FileManager/FileManagerController.php`) — treat it as the standard for new/changed code, not a guarantee about what you'll find in an existing controller.
- **Services**: `app/Services/<Consumer>/<Feature>/` — all business logic and third-party integration (Stripe, MicroBilt, SharePoint, Microsoft, OpenCorporates under `Services/Global/`) lives here, not in controllers or models.
- **Requests**: `app/Http/Requests/Api/V1/<Consumer>/<Feature>/` — FormRequest classes for validation. Used inconsistently in existing code (some endpoints validate inline); new endpoints should add a FormRequest rather than inline `Request` validation.
- **Resources**: `app/Http/Resources/{Borrower,FCH,Global}/` — API Resource classes for response shaping. Not universal (`ApiResponse::success` often returns raw arrays/models); prefer a Resource for any endpoint returning a Model.
- **Models**: `app/Models/<Domain>/<Model>.php`, domain-namespaced to mirror the Services tree (`Borrower/`, `CreditBuilder/`, `Payment/`, `Funding/`, …). All models declare explicit `$fillable`/`$guarded`.
- **Middleware**: role-based (`AdminMiddleware`/`BorrowerMiddleware`/`PartnerMiddleware`) rather than fine-grained permission middleware; `EnsureTenantAccess` enforces multi-tenant/SaaS domain scoping — read `SAAS_DOMAIN_ROUTING.md` and `SAAS_DOMAIN_ROUTING_PRODUCTION.md` before touching any tenant-scoped query.
- **Exceptions**: Laravel 12 style — no `app/Exceptions/Handler.php`; global reporting is registered in `bootstrap/app.php`'s `withExceptions()`. Add case-specific handling there, don't reintroduce a Handler class.
- **Scheduled commands**: `routes/console.php` defines an explicit-time, timezone-pinned schedule with a shared failure handler (log + email notify), driving commands in `app/Console/Commands/` — `MonitorSystemHealth`, `ProcessAllSubscriptions`, `PruneMonitoringHistory`, `PruneOldLogs`, `RestoreDatabaseCommand`, `SchedulerHeartbeat`, `SendFundingApplicationReminders`. Follow the same failure-handler pattern for any new scheduled command.
- **Queue**: `QUEUE_CONNECTION=database` (`.env.example`) — the `composer dev` "queue listener" runs against the database driver, not Redis/SQS.
- `tests/Feature/` exists but is currently empty (no `tests/Unit/` yet) — see TDD below.

## Coding standards
- Follow the Controller → Service → (FormRequest, Resource) → Model layering above for every new endpoint; don't put query/business logic in a controller.
- Namespace/folder mirroring is required: a feature's Controller, Service, Request, and Resource live under the same `<Consumer>/<Feature>` name — don't invent a new top-level folder for one feature.
- Use `App\Helpers\ApiResponse` for every JSON response; don't hand-roll `response()->json()`.
- PHP style follows Laravel Pint defaults (PSR-12) and `.editorconfig` (4-space indent, LF, trim trailing whitespace) — no `pint.json` override exists.
- **Never write a fully-qualified class name in code** (`\App\Models\...`, `app(\App\Services\...::class)`, `\Illuminate\...`). Import the class with a `use` statement at the top of the file and use its short name. When you touch a line that has one, import it there and then. (In a file with no `namespace`, such as `app/Helpers/helpers.php`, a global class like `Exception` needs no `use` - write it without the leading `\`.)

## TDD (strict)
`tests/Feature/` exists but has no test files yet, and `tests/Unit/` doesn't exist — populating both (starting with `tests/Unit/`) is the first task before any TDD-covered work starts here.
1. Write a failing test first.
2. Write the minimum code to pass it.
3. Refactor with the test green.
No production code without a preceding failing test. Never weaken/skip a test to make it pass.

Cover the level the change actually needs, not just one:
- **Unit** (`tests/Unit`) — Services/Actions/helpers in isolation, dependencies mocked, no DB/HTTP.
- **Integration** (`tests/Feature`, no HTTP assertions) — real Eloquent models/repositories against the test DB, e.g. a Service that queries/writes multiple related models.
- **Functional** (`tests/Feature`, HTTP) — a real route through middleware + Passport auth + permission gates, asserting the JSON/status response.

## Compliance & data security
This API is the system of record for applicant PII, credit bureau data (credit-builder/capital-access/microbilt), and payments (Stripe) — see the `code-reviewer` agent's compliance/data-security categories (PCI-DSS, FCRA, GLBA, TCPA, state privacy law). Never store raw card numbers/CVV; encrypt SSN/bank fields at rest; gate every new endpoint with Spatie permissions; keep activitylog audit trails on sensitive-record changes; never write PII/tokens/card data to logs.

**Outstanding gap**: SSN/bank-account encryption-at-rest is not yet implemented — `FundingApplication` has `ssn` fillable with no `encrypted` cast, and `BorrowerBankAccount`/`PaymentMethodBankDetail` only cast date/boolean fields. Treat the rule above as the target, not current behavior, when touching these models.

`opcodesio/log-viewer` is installed and published on every deploy (`log-viewer:publish`) — be aware it can expose log contents (including any accidental PII/token logging) to anyone with route access; don't loosen its route middleware without checking who it's gated to.

## Branching
- `stage` — test branch. All feature work lands here first for QA.
- `release` — production branch. Only what's been verified on `stage` is merged here.
- Feature branches (`feature/*`) branch from `stage` and merge back via PR.
- Hotfixes branch from `release`, then merge to both `release` and `stage`.
- Pushes to `stage`/`release` auto-deploy via `.github/workflows/deploy-stage.yml`/`deploy-release.yml`: rsync+SSH to EC2, `migrate --force`, `optimize`, `queue:restart`, `log-viewer:publish`, Passport key regen/permission-lock, and (stage only) a post-deploy smoke test with a log dump on failure. A merge to either branch is a live deploy against a real database, not just a QA/prod gate.

## Code review
Use the `code-reviewer` agent (`~/.claude/agents/code-reviewer.md`) before merging into `stage` or `release`: architecture, security, duplication, vulnerable dependencies, magic strings, code smells, performance.

## Related projects
Sibling repos under `d:\laragon\www\`, part of the same FCH system. This API is the base project and shared backend for all three — they consume it, it depends on none of them:
- **`fch-borrower-react`** (`client-fch`) — borrower portal (Vite + React), consumes the `v1/borrower` routes.
- **`Fch-admin-frontend-react`** — internal admin/back-office panel (Vite + React), consumes the `v1/admin` (`FCH` controller namespace) routes and manages the CMS/leads/blog content the website reads.
- **`fch-website-react`** — public marketing site (Next.js), consumes only the unauthenticated `v1/website` routes (`routes/website.php`) for CMS content, blog, SEO settings, and lead/pre-qualification submission.
