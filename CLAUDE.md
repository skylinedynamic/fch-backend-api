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
