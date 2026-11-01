# No second invoice for a cycle while its manual payment awaits approval

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
A cycle invoice (platform fee, installment, seat) paid by Cash or Bank Transfer goes to **Processing** until the receiver approves it. If the twice-daily cron (`subscriptions:process-all`) ran before approval, it raised **another invoice for the same cycle**. With a saved card, it then **auto-charged** that invoice, taking a second payment for a month already paid in cash. Approving the cash payment later moved the subscription on another month as well.

Reproduced against the local DB (subscription #8): with its platform fee Processing, `createInvoiceIfDue()` returned `true`.

Cause: `Invoice::scopeOpenCycle()`, the cron's one definition of "this subscriber still owes for this cycle", matched only `Unpaid`. It is used by `createInvoiceIfDue()` and by the `generateDueInvoices()` query.

## Solution
`scopeOpenCycle()` now matches `Unpaid` **or** `Processing`. While a payment awaits approval, no further cycle invoice is raised.
- **Approved:** the invoice is Paid, the subscription moves on, and the cron raises the next cycle as usual.
- **Rejected:** the invoice goes back to Unpaid, stays the open one, and the payer pays it again.

The auto-charge dispatcher already charges only Unpaid invoices, so a Processing invoice is never charged.

## Files changed
- `app/Models/Invoice/Invoice.php`: `scopeOpenCycle()` includes `Processing`.
- `tests/Feature/Billing/SubscriptionBillingCycleTest.php`: 3 tests. While a payment awaits approval, the cron raises nothing, queues no charge, and the guard sees the invoice as open. Once approved, the next cycle is raised. Once rejected, the same invoice stays open and no other is raised.

## Testing
- `a_payment_awaiting_approval_holds_the_next_invoice_back` failed before the fix (2 platform-fee invoices instead of 1), then passed. The approve and reject tests passed before and after.
- `tests/Feature/{Billing,Subscription,Payment,Invoice}`, `tests/Integration/{Subscription,Payment}`, `tests/Unit/Subscription`: 355 passed.
- Full fch-api suite (with the same day's other payment fixes): 4297 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- Duplicate invoices already raised this way are not cleaned up.
