# Invoice window and PDF show the method the invoice was really paid with (BUG-206)

**Date:** 2026-11-11
**Branch:** round-1-run-2-fix

## Problem
QA Round 1 Run 2, BUG-206 (found 10-Nov during the BUG-194 retest), Host admin > Invoices > View Details > PAYMENT DETAILS:
- INV-20261105004 (Enrollment $2,497) and INV-20261105007 (Client Activation Fee $800) were both paid by **Bank Transfer**: payments 374 and 379, refs QA-TEST-REF-0320 / 0322, approved 05-Nov.
- The window still read "Payment Method: Stripe", showed a "Card Holder", and its notes said "To be paid by Stripe."

Causes:
- The invoice window had "Stripe" hard-coded, in both the admin panel and the borrower portal.
- The PDF used `invoice.details['payment_method'] ?? 'Stripe'`. A manual payment never writes that key, so it fell back to Stripe.
- No API response told the screens how the invoice was paid.

BUG-093 had fixed the same problem only for unpaid invoices on the PDF.

## Solution
- **`Invoice::paidWith()`:** reads the payment that pays the invoice. That is the latest `succeeded` payment, or a `processing` one awaiting approval. A failed or rejected attempt doesn't count. It returns:
  - `method`: the payment's `payment_from`, e.g. "Bank Transfer", "Cash Payment", "Stripe"
  - `reference`: the payer's `transaction_number` for a manual payment
  - `is_card`: whether `payment_type` is Card

  For an unsaved invoice it returns empty values without querying.
- **FCH and Borrower `InvoiceDetailResource`:** add `payment_method`, `payment_reference` and `is_card_payment`. They are null / null / false when nothing has paid the invoice.
- **PDF (`invoices/download.blade.php`):**
  - Payment Method comes from the payment record. An older invoice with no record keeps the old fallback.
  - A "Reference:" line is printed when there is a reference.
  - The payer line is "Card Holder" only for a card payment. It is "Paid By" for anything else, and still "Payee Name" for a commission payout.
  - The card number is printed only for a card payment.
  - The notes leave out "To be paid by Stripe." once the invoice was paid another way.

## Files changed
- `app/Models/Invoice/Invoice.php`: `PAYING_PAYMENT_STATUSES`, `paidWith()`
- `app/Http/Resources/FCH/Invoice/InvoiceDetailResource.php`: `paidWithPayload()` fields
- `app/Http/Resources/Borrower/Invoice/InvoiceDetailResource.php`: same
- `resources/views/invoices/download.blade.php`: Payment Details and notes from `paidWith()`

## Testing
New tests were written first and failed (7 failed), then passed:
- `tests/Integration/Invoice/InvoiceDetailResourceTest.php`: the payload keys now include the three fields. New tests:
  - Bank Transfer with its reference, in both portals
  - card payment through Stripe
  - a cash payment awaiting approval
  - unpaid, and unpaid after a rejected attempt
- `tests/Feature/Invoice/InvoicePdfBilledToTest.php`: new `an_invoice_paid_by_bank_transfer_prints_the_transfer_not_a_card` ("Payment Method: Bank Transfer", "Reference: …", "Paid By: …", no Card Holder, no "Stripe").
- The existing PDF tests (`InvoicePdfPaymentDetailsTest`, the rest of `InvoicePdfBilledToTest`) still pass.
- `tests/Feature/Invoice`, `tests/Feature/Payment`, `tests/Integration/Invoice`, `tests/Unit/Models`: 269 passed.
- A full-suite run (5,368 passed) overlapped with these edits. Its 13 failures were all in these invoice/payment PDF tests, and they pass on the re-run above.
- Admin and borrower side: `react-fch/docs/handoff/2026-11-11-bug-206-invoice-shows-the-real-payment-method.md`, `borrower-fch/docs/handoff/2026-11-11-bug-206-invoice-shows-the-real-payment-method.md`.

## Open items
- An invoice paid before payment records existed still shows whatever the invoice saved, or "Stripe". That is unchanged on purpose.
- The PDF was not checked by QA (download needs approval). It is covered by the feature test above.
