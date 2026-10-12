# Password change (F83, was O24)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F83 (was O24)

## Problem
A changed password left every other token alive.

## Solution
`User::revokeOtherTokens()` (access and refresh tokens, except the current one) on both partner and borrower password change.

## Files changed
- `app/Models/User/User.php`
- `app/Services/FCH/Common/AuthProfile/AuthProfileService.php`
- `app/Services/Borrower/Auth/AuthProfileService.php`
- `tests/Feature/User/AuthProfileApiTest.php`

## Tests
- `Feature/User/AuthProfileApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
