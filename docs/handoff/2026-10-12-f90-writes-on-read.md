# Writes on read (F90, was O41)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F90 (was O41)

## Problem
The strategy list/overview GETs created `borrower_capital_access_plays` rows ("legacy data sync").

## Solution
The read path no longer writes; migration `2026_10_12_000100_backfill_borrower_capital_access_plays` creates the missing rows once (re-runnable).

## Files changed
- `app/Services/Borrower/CapitalAccess/CapitalAccessStrategyService.php`
- `database/migrations/2026_10_12_000100_backfill_borrower_capital_access_plays.php (new)`
- `tests/Integration/CreditBuilder/CapitalAccessReadOnlyTest.php (new)`

## Tests
- `Integration/CreditBuilder/CapitalAccessReadOnlyTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Run `php artisan migrate` on deploy so the backfill runs.
