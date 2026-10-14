# Tests vs MySQL (F81, was O21/O33)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F81 (was O21/O33)

## Problem
`CONCAT`, `DATE_FORMAT`, `CONVERT_TZ` could not run on the suite's SQLite, so those queries were untested; the funding-type FK migration left a stale FK on SQLite (fixed in F68).

## Solution
SQLite shims with MySQL semantics registered in `TestCase` (as `CONCAT_WS` already was); the Verification Center queue now has a feature test.

## Files changed
- `tests/TestCase.php`
- `tests/Unit/Support/SqliteMysqlFunctionsTest.php (new)`
- `tests/Feature/CreditBuilder/HostCreditBuilderApiTest.php`

## Tests
- `Unit/Support/SqliteMysqlFunctionsTest`, `Feature/CreditBuilder/HostCreditBuilderApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
