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
- `FetchPayment` accepts `payment_from` (nullable string), `transaction_type` (nullable, `Debit` or `Credit` from `Payment::TRANSACTION_TYPE_*`) and `is_environment` (nullable boolean).
- **Environment** (follow-up, same day): `is_environment` was dropped the same way, so choosing "Production" still listed sandbox payments. `list()` now filters `payments.is_environment`: 1 is Production, 0 is Sandbox, and null applies no filter. The check is not `!empty()`, because Sandbox is 0.
- `list()` filters on them:
  - **Method:** `payments.payment_from` must equal the value sent.
  - **Type:** uses the rule the DEBIT/CREDIT column already shows a borrower (`Payment::getTransactionTypeForUser`). A refunded payment or a payout is a Credit; every other payment is a Debit. The Debit condition is null-safe, so a payment with no `payment_type` still counts as a Debit.
- "All Methods" and "All Types" are meant to send `null`, which applies no filter. In practice, PrimeReact's Dropdown sent the whole option object, and with these new rules that returned a 422 and an empty list. This is fixed in `client-fch`; see `client-fch/docs/handoff/2026-11-06-bug-169-payments-all-filter-sends-null.md`.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/Payment/FetchPayment.php`: rules for `payment_from`, `transaction_type` and `is_environment`.
- `app/Services/Borrower/Payment/PaymentService.php`: Method, Type and Environment filters in `list()`.

## Tests
- `tests/Feature/Payment/BorrowerInvoicesAndPaymentsTest.php`, 3 new tests:
  - `the_payment_list_filters_by_method`: Square, Stripe, an unknown method, and all.
  - `the_payment_list_filters_by_type_as_the_table_shows_it`: Credit returns the refund and the payout; Debit returns the rest, including a payment with a null type; the filter matches the row's `transaction_type`.
  - `method_type_and_status_filters_apply_together`.
  - `the_payment_list_filters_by_environment`: Production, Sandbox, and all. Red first (Production returned the sandbox payment too).
- Red first: the first 3 failed before the fix. After the first version of the fix, the null-type case failed (SQL `NOT` with NULL). The Debit condition was made null-safe and the test now passes.
- `tests/Feature/Payment`, `tests/Feature/Invoice` and `BorrowerCommonRouteAccessTest`: 234 passed. After the Environment follow-up, `tests/Feature/Payment` and `tests/Feature/Invoice` passed (187 tests).

## Open items
- Found while checking the other filters, not fixed yet:
  - The date filter on both the borrower and the common Payment lists compares the UTC date, but the table shows the viewer's day. For example, payment #103 shows 11-03 (Dhaka) but is found only by 11-02.
  - Choosing only a start date in a Date Range, then clicking Filters, crashes `formatDate(null)` on the client and admin Payments and Invoices pages, and the list is not updated. Client `Payments.jsx` also uses `toast` without importing it.
  - Admin `PaymentFilters.jsx` Dropdowns lack `optionValue`. This works only because `FCH\Common\PaymentService::extractFilterValue` unwraps the option object.
- `client-fch` `pages/payments/Payments.jsx` calls `fetchPayments` directly instead of through a module query hook. This is the existing pattern and was not touched here.
