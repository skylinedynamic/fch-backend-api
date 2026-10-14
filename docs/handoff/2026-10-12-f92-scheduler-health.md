# Scheduler health (F92, was O43)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F92 (was O43)

## Problem
Failed only after 2 h without a heartbeat (the heartbeat runs every minute).

## Solution
10 minutes.

## Files changed
- `app/HealthChecks/SchedulerRanCheck.php`
- `tests/Integration/Console/SchedulerMaintenanceCommandsTest.php`

## Tests
- `Integration/Console/SchedulerMaintenanceCommandsTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
