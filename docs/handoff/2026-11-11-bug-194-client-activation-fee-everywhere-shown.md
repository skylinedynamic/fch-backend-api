# BUG-194 retest: "Client Activation Fee" wherever the fee is shown, `borrower_fee` wherever it is stored

**Date:** 2026-11-11
**Branch:** round-1-run-2-fix

## Problem
QA Round 1 Run 2, BUG-194 retest on 10-Nov: **Partially Fixed**.
- Partner (QA-TEST Cert Partner) > My Subscription > Recent Payments History still showed INVOICE TYPE "Borrower Fee" on the $800 rows (INV-20261103002, INV-20261021009, ...).
- The earlier fix (`2026-11-10-bug-194-client-activation-fee-invoice-name.md`) named the type through `Invoice::typeLabel()` only in the invoice resources, the PDF and the email.
- The payment rows behind My Subscription sent only the stored type. The admin panel turned that into its own label, which still said "Borrower Fee".

Checking further found three more places with the same problem:
- **Upcoming Billings:** the borrower fee row's `upcoming_billing_type` came from `lang/en/billing.php` ("Borrower Fee").
- **Invoices Type column search:** "Client Activation Fee" was turned into `client_activation_fee`, which matches no stored type. The rows the table now labels "Client Activation Fee" could not be found by that name.
- **Upcoming Billings Billing Type search:** the same problem.

## Solution
Rule agreed with Awlad:
- No database change.
- The API sends the name "Client Activation Fee".
- Saving and filtering keep using the stored `borrower_fee`.

Changes:
- **`Invoice::typesMatchingLabel(string)`:** a new reverse lookup. It returns the stored types whose shown name contains the typed text, ignoring case ("client act" → `borrower_fee`; "fee" → `platform_fee`, `borrower_fee`). The label map stays `INVOICE_TYPE_LABELS`, so there is one place for the name.
- **`PaymentResource`:** adds `invoice_type_label` (`Invoice::typeLabel()`) beside the existing `invoice_type` code. This feeds My Subscription and borrower billing recent payments.
- **`BillingResource`:** the borrower fee row's `upcoming_billing_type` is `Invoice::typeLabel(INVOICE_TYPE_BORROWER_FEE)`. `billing.type.borrower_fee` is removed from `lang/en/billing.php`; nothing else used it.
- **`InvoiceService` Type column filter:** matches the types whose shown name holds the text, or the stored form of the text ("Borrower Fee" still works). The query stays on `invoices.type`.
- **`BillingService` Billing Type search:** a name that matches `borrower_fee` ("Client Activation Fee", "activation") picks the fee rows, as `borrower_fee` / "Borrower Fee" already did.
- **Saving:** unchanged. Invoices are still created with `Invoice::INVOICE_TYPE_BORROWER_FEE`.

## Files changed
- `app/Models/Invoice/Invoice.php`: `typesMatchingLabel()`
- `app/Http/Resources/FCH/Common/Payment/PaymentResource.php`: `invoice_type_label`
- `app/Http/Resources/FCH/Billing/BillingResource.php`: fee row type from `Invoice::typeLabel()`
- `app/Services/FCH/Common/Invoice/InvoiceService.php`: Type column filter by shown name or stored form
- `app/Services/FCH/Common/Billing/BillingService.php`: Billing Type search finds the fee by its shown name
- `lang/en/billing.php`: removed `type.borrower_fee`
- `docs/test-findings.md`: O85 closed

## Testing
Each new test was written first and seen to fail (10 failed, then 1 more for the billing search), then passed:
- **Unit:** `tests/Unit/Models/InvoiceTypeLabelTest.php`, new dataset `a_typed_name_gives_the_stored_types_it_names` (7 cases: full name, part of the name in any case, another type, a shared word, surrounding spaces, unknown, blank).
- **Feature:**
  - `InvoicePayerAndTypeFilterTest::the_type_column_filter_finds_the_borrower_fee_by_its_new_name`: "Client Activation Fee" and "activation" return the borrower_fee rows. The existing "Borrower Fee" test still passes.
  - `MySubscriptionRecentPaymentsTest::recent_payments_name_the_borrower_fee_a_client_activation_fee`: `invoice_type_label` "Client Activation Fee", `invoice_type` still `borrower_fee`.
  - `UpcomingBillingReceivablesTest`: the fee row's type is "Client Activation Fee" (updated). New `the_billing_type_search_finds_the_fee_by_its_new_name`.
- **Related suites** (`Feature/Invoice`, `Feature/Billing`, `Feature/MySubscription`, `Feature/Payment`, `Feature/Subscription`, `Integration/Invoice`, `Unit/Models`): 393 passed.
- **Full suite:** 5,368 passed. The 13 failures in that run were PDF/payment tests that overlapped with the BUG-206 edits being made at the time, and they pass on re-run (see the BUG-206 handoff).
- **Admin side:** `react-fch/docs/handoff/2026-11-11-bug-194-client-activation-fee-everywhere-shown.md`. Live E2E against this API: `invoices-filters.live` and `upcoming-billings/borrower-fee.live`, 5 passed.

## Open items
- `invoice_type_label` is new on `PaymentResource`. The borrower portal's Payments page could use it too if it ever shows the type. It doesn't today.
- Internal names (`borrower_fee`, `borrower_fee_amount`, `BorrowerFeeInvoiceNotificationMail`) stay as they are on purpose.
- The QA sheet's BUG-194 row can be retested: My Subscription history, Upcoming Billings, Subscription Details, and the Invoices Type filter.
