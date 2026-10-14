# Co-borrower form (F87, was O28)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F87 (was O28)

## Problem
`ownership` was `numeric` with no bounds.

## Solution
`min:0|max:100` on the borrower and partner forms.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/MyApplication/CoBorrowerValidationRequest.php`
- `app/Http/Requests/Api/V1/FCH/Partner/FundApplication/CoBorrowerValidationRequest.php`
- `tests/Feature/Borrower/MyApplicationsApiTest.php`

## Tests
- `Feature/Borrower/MyApplicationsApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
