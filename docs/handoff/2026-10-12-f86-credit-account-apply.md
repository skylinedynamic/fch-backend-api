# Credit-account apply (F86, was O27)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F86 (was O27)

## Problem
`apply` did not re-check the list's eligibility (FICO, time in business).

## Solution
`CreditAccount::offeredTo()` scope used by both list and apply (404 when not offered). **Open:** the tier lock (`portal_tier_unlock`) has no server-side meaning yet - needs a decision.

## Files changed
- `app/Models/CreditBuilder/CreditAccount.php`
- `app/Services/Borrower/CreditBuilder/CreditAccountService.php`
- `tests/Feature/CreditBuilder/BorrowerCreditBuilderApiTest.php`

## Tests
- `Feature/CreditBuilder/BorrowerCreditBuilderApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
The tier lock (`portal_tier_unlock`) shown on the screen is still not enforced by the API - it has no defined server-side meaning yet. Needs a decision.
