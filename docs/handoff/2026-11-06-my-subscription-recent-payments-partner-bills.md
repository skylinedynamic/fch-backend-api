# My Subscription: Recent Payments lists the partner's plan, seat and borrower fee payments

**Date:** 2026-11-06

## Problem
A partner's My Subscription → Recent Payments History listed only payments whose invoice belonged to the partner's current plan subscription. It also listed payments with no invoice.

The partner's seat payments (on the seat's own subscription) and borrower fee payments (on the client's subscription) never showed. Nothing said which kind of invoice a payment was for.

Requested 2026-11-06: show the partner's own plan invoices, seat payments and borrower fees, each with its invoice type.

## Solution
- `Invoice::PARTNER_BILL_TYPES`: enrollment, down_payment, installment, platform_fee, user_seat, borrower_fee. This is what a partner pays the host. `commission_payout` is money going the other way.
- `MySubscriptionService::getSubscriptionDetails()`, recent payments:
  - The partner's own payments (`entity` = the partner) whose invoice type is in `PARTNER_BILL_TYPES`, or untyped (legacy plan charges).
  - Newest first, at most 10, as before.
  - A commission paid to the partner and payments with no invoice are left out.
- `PaymentResource`: adds `invoice_type` (the invoice's stored type). This is additive and shared by the payment lists.
- Also moved `Invoice::OPEN_STATUSES` (added earlier today for Upcoming Billings) above `CYCLE_INVOICE_TYPES`'s doc comment. It had been placed between the two.
- Admin panel: `Fch-admin-frontend-react/docs/handoff/2026-11-06-my-subscription-layout-and-invoice-type.md`.

## Files changed
- `app/Models/Invoice/Invoice.php`: `PARTNER_BILL_TYPES`; constant order.
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`: the recent payments query.
- `app/Http/Resources/FCH/Common/Payment/PaymentResource.php`: `invoice_type`.
- `tests/Feature/MySubscription/MySubscriptionRecentPaymentsTest.php` (new).

## Testing
- `MySubscriptionRecentPaymentsTest`: failed first (seat and borrower fee payments missing), then passed. It checks:
  - enrollment, installment, platform fee, seat and borrower fee payments are listed, each with its `invoice_type`
  - a commission payout and another partner's payment are not listed
- `tests/Feature/MySubscription`, `PartnerAccountApiTest`, `ResumeSubscriptionTest`, `tests/Feature/Payment`: 215 passed.
- Local MySQL, real partners:

  | Partner | Before | Now |
  |---|---|---|
  | 6 | enrollment only | borrower fee + enrollment (its $4,000 commission payout is not listed) |
  | 17 | | 3 borrower fee payments + enrollment |
  | 41 | | borrower fee + enrollment |
  | 42 | | down payment |

## Open items
- **Failed auto-charge attempts:** each one is its own payment, so partner 16's 10 FAILED attempts on INV-20260901001 fill the whole list (limit 10, unchanged).
- **Current / Recent Bill card (BUG-187):** this card still picks any partner invoice with no subscription, including a commission payout. Not changed here.
- Not committed.
