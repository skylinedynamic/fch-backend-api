# Credit profile (F74, was O7)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F74 (was O7)

## Problem
`saveCreditProfile` saved the borrower's score and `credit_profile_updated` before opening its transaction; a later failure left them half-updated.

## Solution
Transaction opened before the first write.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php`
- `tests/Feature/Borrower/BorrowerCreditProfileApiTest.php`

## Tests
- `Feature/Borrower/BorrowerCreditProfileApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
