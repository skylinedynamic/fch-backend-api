# Stub payment drivers (F98, was O52)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F98 (was O52)

## Problem
Square / eCrypt / Authorize.Net `charge()` returned success with a made-up transaction id.

## Solution
Every call fails closed (`success => false`, "not available yet").

## Files changed
- `app/Services/GlobalPayment/Drivers/SquarePaymentDriver.php`
- `app/Services/GlobalPayment/Drivers/EcryptPaymentDriver.php`
- `app/Services/GlobalPayment/Drivers/AuthorizeNetPaymentDriver.php`
- `tests/Unit/Payment/StubPaymentDriversTest.php (new)`

## Tests
- `Unit/Payment/StubPaymentDriversTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
