# Unpaid invoice PDF no longer reads as a Stripe card payment (BUG-093)

Date: 2026-10-27

## Problem
Admin › Invoices › an unpaid invoice (e.g. INV-20261020009) › Download PDF. The "Payment Details" block printed:
- Payment Method: Stripe
- Card Holder: the customer's name
- Total Amount: $1,000.00

Nothing had been paid. In `resources/views/invoices/download.blade.php` the payment method falls back to "Stripe", and Card Holder and Total Amount are printed whatever the status.

## Solution
While an invoice is not paid (any status other than Paid, Success or Processing) and is not a commission payout, the block shows:
- Payment Method: Not paid yet
- Payment Status: the invoice status
- Amount Due: the amount

There is no card holder or card number. Processing counts as paid, because a payment was made and is waiting for approval. Paid invoices and commission payouts print as before. The same view is used for the download and the e-mailed invoice, so both change.

## Files changed
- `resources/views/invoices/download.blade.php`: not-yet-paid branch in Payment Details.
- `tests/Feature/Invoice/InvoicePdfPaymentDetailsTest.php`: new. Renders the view for Unpaid, Overdue and Failed, plus a Processing case and a Paid case.

## Testing
- The new tests failed first (Unpaid, Overdue, Failed), then passed. `tests/Feature/Invoice`: 15/15.

## Open items
- None.
