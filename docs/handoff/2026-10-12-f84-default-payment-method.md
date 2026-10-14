# Default payment method (F84, was O25)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F84 (was O25)

## Problem
Any `provider_name` string was stored as the default.

## Solution
`PaymentProvider::isOfferedBy()` - borrower: their partner's switched-on gateways; partner: the host's. 422 otherwise.

## Files changed
- `app/Models/PaymentMethod/PaymentProvider.php`
- `app/Services/Borrower/Billing/BorrowerBillingService.php`
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`
- `lang/en/payment_method.php`
- `tests/Feature/Billing/BorrowerBillingApiTest.php`
- `tests/Feature/Partner/PartnerAccountApiTest.php`
- `tests/Integration/Payment/BorrowerCardSaveTest.php`

## Tests
- `Feature/Billing/BorrowerBillingApiTest`, `Feature/Partner/PartnerAccountApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
A borrower or partner whose saved default is a gateway that is not switched on keeps it until they change it; only new choices are checked.
