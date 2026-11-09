# Invoice Expired status: comments match the code (BUG-021, round 2)

**Date:** 2026-11-01
**Branch:** bugfix/round-2

## Problem
QA round 2 **BUG-021** (R2-OP-INV-05) asked why unpaid invoices never become Overdue. They aren't meant to. An unpaid invoice leaves `subscription_end_date` where it is, so the profile locks once today passes it. A late payment starts the next month from the payment date (`SubscriptionService::nextCycleFrom()`).

While checking this, two comments turned out to describe retired behaviour. `Subscription::unpaidInvoices()` and the installment limit check in `SubscriptionService` both said a missed cycle "generates an Expired row". Nothing has written `Invoice::STATUS_EXPIRED` since commit `a87d3fff` (2026-10-11). Older invoices still carry it (5 in the local DB).

## Solution
Comments only, no behaviour change:
- `Invoice::STATUS_EXPIRED` notes that it is no longer set but older invoices still carry it.
- The `unpaidInvoices()` and installment-limit comments now say the same.

The admin panel's filter fix (Overdue removed, Expired added, Canceled spelling) is in `Fch-admin-frontend-react/docs/handoff/2026-11-01-invoice-status-filter-and-commission-partner-filter.md`.

## Files changed
- `app/Models/Invoice/Invoice.php`: comment on `STATUS_EXPIRED`.
- `app/Models/Subscription/Subscription.php`: `unpaidInvoices()` docblock.
- `app/Services/FCH/Subscription/SubscriptionService.php`: installment limit comment.

## Testing
No new tests (comments only). `tests/Feature/Subscription` and `tests/Feature/Invoice`: 23 passed. `PartnerOptionsApiTest` and `tests/Feature/Commission` (BUG-022): 35 passed.

## Open items
- `InvoiceService` (common) stats still include `STATUS_OVERDUE` in `unpaid_amount`. It's harmless because nothing sets it.
- `Invoice::STATUS_OVERDUE` is still used by `InvoiceSeeder`, so local seed data can show Overdue rows that production never has.
