# Business info errors (F85, was O26)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F85 (was O26)

## Problem
A 500 returned the raw exception message.

## Solution
Logged; translated message returned.

## Files changed
- `app/Http/Controllers/Api/V1/Borrower/Profile/BusinessInfoController.php`
- `tests/Feature/Borrower/BorrowerProfileApiTest.php`

## Tests
- `Feature/Borrower/BorrowerProfileApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
