# Integration test backfill and the bugs it found

**Date:** 2026-10-12
**Branch:** bugfix-awlad (uncommitted)
**Author:** Awlad Hossain (with Claude Code)

## Problem
- Everything the app does **off a request** had no integration test: the scheduled console commands, the schedule itself, the reminder job and sweep, the scheduler health check, and the emails (only 3 of 53 mailables had ever been rendered in a test). A broken one only showed up in production — as a failed queue job or a cron run that did the wrong thing.
- Six test files under `tests/Unit` used the database, against the suite's own rule ("Unit: no database").
- Writing the tests turned up seven bugs (below).

## Solution
**Test structure**
- Moved the six database-backed unit tests to `tests/Integration` (`CreditBuilder/*`, `Http/AccessMiddlewareTest`); split `ApiResponseAndPaginationTest` so its database test is now `Integration/Support/VisibleToUserTest`. Unit is database-free again.
- Ran the whole suite with a class-load trace to find service classes no test ever executes; covered every live one. What is left unloaded is dead code (O53) or dormant SharePoint storage (O54).

**Bugs fixed (each test-first; full detail in `docs/test-findings.md`)**
| ID | Problem | Fix |
|---|---|---|
| F59 | `logs:prune-months` deleted the previous month's log folder on the 29th–31st (`strtotime("-1 months")` overflow). | Count back from the 1st with Carbon. |
| F60 | `backup:restore` read the local disk in production (`env()` under config cache), exited 0 on failure, and put the DB password on the `mysql` command line. | Default to the backup destination disk from config; failure exit codes; password via `MYSQL_PWD`. |
| F61 | Borrower emails (request approved, application reminder, underwriting update) crashed on `make_url(null)` when the partner has no borrower domain — never sent. | `concatUrl(..., config('app.frontend_borrower_url'))` fallback. |
| F62 | MicroBilt client secret written to the log on every verification. | Removed from the log line. |
| F63 | A MicroBilt outage **rejected** the borrower's Tier 0 task permanently. | Task left unchanged with "service could not be reached". |
| F64 | Bulk API verification checked every client against the **first** client's name/EIN and emailed only the first client. | Each request uses its own borrower; one email per borrower. |
| F65 | OpenAI credit-report parser hid "could not parse the reply" behind a generated summary. | Failure reason kept as the summary. |

## Files changed
- `app/Console/Commands/PruneOldLogs.php` — months to keep counted from the 1st of the month (F59)
- `app/Console/Commands/RestoreDatabaseCommand.php` — default disk, exit codes, `MYSQL_PWD` (F60)
- `resources/views/emails/borrower/{funding_request_approved,funding_application_reminder,underwriting_status_updated}.blade.php` — link fallback (F61)
- `app/Services/Global/MicroBilt/MicroBiltService.php` — secret out of the log (F62)
- `app/Services/FCH/Common/CreditBuilder/VerificationCenterService.php` — outage handling, per-request borrower, per-borrower email (F63, F64)
- `lang/en/verification_center.php` — `verification_service_unavailable`
- `app/Services/FCH/Common/Borrower/OpenAiParserService.php` — keep the parse-failure reason (F65)
- `CLAUDE.md` (new), `docs/handoff/` (new), `docs/test-findings.md` — F59–F65, D12–D13, O42–O55

## Tests
- Added (Integration):
  - `Console/FundingApplicationRemindersCommandTest` — day 1/3/7 steps, calendar days, once per step
  - `Console/SchedulerMaintenanceCommandsTest` — heartbeat, health check, log and monitoring pruning, health snapshot
  - `Console/RestoreDatabaseCommandTest`, `Console/ScheduleTest` (times, timezone, failure alert)
  - `Console/VerifyAssistantKnowledgeCommandTest`, `Console/VerifyInternalAueCommandTest`
  - `Reminder/DispatchReminderDueJobTest` — job + sweep push a reminder exactly once
  - `Mail/AccountAndPaymentMailRenderTest`, `Mail/RecordMailRenderTest` — every live mailable renders with its data
  - `CreditBuilder/MicroBiltVerificationTest`, `CreditProfile/OpenAiCreditReportParserTest`
- Moved Unit → Integration: `CreditBuilder/{ApprovalOdds,ApprovalProbability,PlayAccessAndScoreConfig,ReadinessScoreCalculation}Test`, `Http/AccessMiddlewareTest`; new `Support/VisibleToUserTest`.
- Suites run: **Unit 733 passed** (42 skipped — frontend route-map checks); **Integration 314 passed, 18 failed**; **Feature 1071 passed**. The 18 failures are the known U1 regression (`PreQualificationAgentRunTest`, `BorrowerSafeAgentOutputTest`), recorded only per decision.

## Open / follow-up
- **Rotate the MicroBilt client secret** — it is in existing log files (F62).
- Decisions waiting: **D12** (change-request email shows SSN-last-4 / DOB / EIN in plain text), **D13** (should `backup:restore` confirm / refuse in production).
- **O51** — no Stripe webhook route; disputes, refunds and renewal failures made on Stripe's side never reach the app.
- Not coverable on SQLite: ~30 MySQL-only queries (search/date filters, statistics). Proposed: run Integration + Feature against a MySQL service container in CI, and add pcov for a line-coverage report.
- U1 production regression still open (recorded, not fixed).
