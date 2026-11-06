# BUG-169: Client Payments list filters by Method and Type

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2
**Author:** awlad

## Problem
On the Client portal's Payments page, the Method and Type filters had no effect. Choosing **Square**, or Type **Credit**, still listed the client's Stripe card DEBIT payment. The Status filter worked.

The client sends both values: `payment_from` and `transaction_type` on `POST /borrower/payments/fetch-payments`. On the server they were lost in two places:
- `Borrower\Payment\FetchPayment` had no rule for either field, so `$request->validated()` dropped them before they reached the service.
- `Borrower\Payment\PaymentService::list()` had no code for them anyway.

The admin and partner payment list (`FCH\Common\PaymentService`) already handles both filters. Only the borrower API was missing them.

## Solution
- `FetchPayment` accepts `payment_from` (nullable string) and `transaction_type` (nullable, `Debit` or `Credit` from `Payment::TRANSACTION_TYPE_*`).
- `list()` filters on them:
  - **Method:** `payments.payment_from` must equal the value sent.
  - **Type:** uses the rule the DEBIT/CREDIT column already shows a borrower (`Payment::getTransactionTypeForUser`). A refunded payment or a payout is a Credit; every other payment is a Debit. The Debit condition is null-safe, so a payment with no `payment_type` still counts as a Debit.
- "All Methods" and "All Types" send `null`, so no filter is applied, as before.
- No frontend change: `client-fch` `Payments.jsx` already sends both fields.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/Payment/FetchPayment.php`: rules for `payment_from` and `transaction_type`.
- `app/Services/Borrower/Payment/PaymentService.php`: Method and Type filters in `list()`.

## Tests
- `tests/Feature/Payment/BorrowerInvoicesAndPaymentsTest.php`, 3 new tests:
  - `the_payment_list_filters_by_method`: Square, Stripe, an unknown method, and all.
  - `the_payment_list_filters_by_type_as_the_table_shows_it`: Credit returns the refund and the payout; Debit returns the rest, including a payment with a null type; the filter matches the row's `transaction_type`.
  - `method_type_and_status_filters_apply_together`.
- Red first: all 3 failed before the fix. After the first version of the fix, the null-type case failed (SQL `NOT` with NULL). The Debit condition was made null-safe and the test now passes.
- `tests/Feature/Payment`, `tests/Feature/Invoice` and `BorrowerCommonRouteAccessTest`: 234 passed.

## Open items
- The client page also sends `is_environment`. The borrower API drops it in the same way, but the client page shows no Environment filter, so this has no visible effect. Not changed here.
- `client-fch` `pages/payments/Payments.jsx` calls `fetchPayments` directly instead of through a module query hook. This is the existing pattern and was not touched here.
