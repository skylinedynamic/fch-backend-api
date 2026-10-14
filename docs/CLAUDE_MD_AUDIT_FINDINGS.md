# CLAUDE.md Audit Findings — fch-api (fch-backend-api)

Generated 2026-08-30. Result of scanning `CLAUDE.md` against the actual repo (composer.json, `app/`, `routes/`, `bootstrap/app.php`, `.github/workflows/`) to find stale claims and undocumented conventions. Nothing here has been applied to `CLAUDE.md` yet — treat this as a to-do list for the next doc update.

## Stale / incorrect claims in CLAUDE.md

1. **"No `tests/` directory exists yet" (CLAUDE.md:20, 29)** — false as stated. `tests/Feature/` already exists (the directory is there, just empty of test files; there is no `tests/Unit/`). The doc's premise ("creating it... is the first task") needs to change to "populate it," not "create it."

2. **"never build a raw `response()->json()` in a controller" (CLAUDE.md:13)** — violated today in `app/Http/Controllers/Api/V1/Borrower/Funding/FundingRequestController.php` (lines 35, 53, 71, 77, 94, 100, 118, 123 — 8 occurrences) and in at least 12 other controllers, including `Api/V1/FCH/Common/Contact/ContactController.php`, `Api/V1/Global/FileManager/FileManagerController.php`, and `Api/V1/FCH/Admin/Resource/ResourceController.php`. This isn't an isolated exception — the rule should hold for *new* code, but the doc currently reads as if it's already universally true.

3. **Compliance claim "encrypt SSN/bank fields at rest" (CLAUDE.md:41) is aspirational only — not implemented.** `app/Models/Borrower/FundingApplication.php:59` has `ssn` in `$fillable` with no `encrypted` cast anywhere in `$casts` (lines 113-127). `app/Models/Borrower/BorrowerBankAccount.php:20-22` and `app/Models/PaymentMethod/PaymentMethodBankDetail.php:27-30` only cast `date`/`boolean` fields. The only real `encrypted` cast usage found anywhere is an unrelated settings-key encryptor in `app/Models/Setting/EmailConfiguration.php:21-56`. This should be flagged as an outstanding compliance gap, not described as current behavior.

4. **Commands section (CLAUDE.md:5-9)** is missing the `composer setup` script (install, `.env` copy, `key:generate`, `migrate --force`, npm install/build) defined in `composer.json`.

**Confirmed still accurate**: routing files/registration (`bootstrap/app.php:11-19`); controller consumer namespaces (Borrower/FCH/Global/GlobalPayment); middleware aliases and `EnsureTenantAccess`; `SAAS_DOMAIN_ROUTING*.md` both exist at repo root; no `Handler.php`, with `withExceptions()` in `bootstrap/app.php:41-49`; `pint`/`pint.bat` present in `vendor/bin/`; `test`/`dev` composer scripts match exactly.

## Missing from CLAUDE.md

1. **CI/CD deploy pipeline**: `.github/workflows/deploy-stage.yml` and `deploy-release.yml` auto-deploy on push to `stage`/`release` (EC2 via rsync+SSH), running `migrate --force`, `optimize`, `queue:restart`, `log-viewer:publish`, Passport key generation/permission-lock, and (stage only) a post-deploy smoke test + log dump on failure. None of this is in the Branching section.

2. **`opcodesio/log-viewer`** package (in `composer.json`), published via `php artisan log-viewer:publish` in both deploy scripts — an undocumented log-viewing tool.

3. **Scheduled commands**: `routes/console.php` defines an explicit-time, timezone-pinned schedule with a shared failure handler (log + email notify), wrapping commands in `app/Console/Commands/`: `MonitorSystemHealth`, `ProcessAllSubscriptions`, `PruneMonitoringHistory`, `PruneOldLogs`, `RestoreDatabaseCommand`, `SchedulerHeartbeat`, `SendFundingApplicationReminders`. This is a significant, deliberately-designed convention with no mention anywhere in the doc.

4. **Queue driver**: `.env.example` sets `QUEUE_CONNECTION=database` — the doc mentions "queue listener" in `composer dev` but never states the driver.

5. No Scribe/L5-Swagger API-doc tooling exists in `composer.json` — not a stale claim (the doc doesn't claim one), just worth noting as an absence if API documentation becomes a priority.

## Suggested next step
Fold the items above into `CLAUDE.md`: correct the three stale/aspirational claims (tests dir, `ApiResponse` rule, SSN encryption), add the `composer setup` script to Commands, and add a short "Ops" subsection (or extend Branching) covering deploy workflows, scheduled commands, log-viewer, and the queue driver.
