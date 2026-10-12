# Log retention (F91, was O42)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F91 (was O42)

## Problem
Log folders were month-only (June 2025 = June 2026); `scheduler.log` grew for ever.

## Solution
App and scheduler logs written to `logs/{Y}/{F}/`; the pruner keeps six real months and removes empty year folders; old month-only folders are still handled.

## Files changed
- `app/Logging/MonthlyDailyLogger.php`
- `routes/console.php`
- `app/Console/Commands/PruneOldLogs.php`
- `tests/Integration/Console/SchedulerMaintenanceCommandsTest.php`
- `tests/Unit/Logging/MonthlyLogPathTest.php`

## Tests
- `Integration/Console/SchedulerMaintenanceCommandsTest`, `Unit/Logging/MonthlyLogPathTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Old month-only folders (e.g. `storage/logs/June/`) are still pruned by month name until they age out.
