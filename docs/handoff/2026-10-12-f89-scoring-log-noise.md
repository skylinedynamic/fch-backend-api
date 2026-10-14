# Scoring log noise (F89, was O40)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F89 (was O40)

## Problem
`ApprovalOddsService` wrote 4-5 info lines per card on every render.

## Solution
Debug level.

## Files changed
- `app/Services/Borrower/CapitalAccess/ApprovalOddsService.php`
- `tests/Integration/CreditBuilder/ApprovalOddsTest.php`

## Tests
- `Integration/CreditBuilder/ApprovalOddsTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
