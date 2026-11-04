# BUG-049: the last installment takes up the monthly rounding

**Date:** 2026-11-03

## Problem
An installment plan's monthly amount is the balance divided by the months, rounded to the cent and stored as `decimal(10,2)`. The billing cron then billed that same amount every month and never adjusted the last one, so the installments did not add up to the plan:

- **Charged too much:** 6696.70 over 12 months is 558.06 a month, so 12 × 558.06 = 6696.72, 2 cents over the plan.
- **Charged too little:** 1000 over 3 months is 333.33 a month, so 3 × 333.33 = 999.99, 1 cent short.

The client confirmed the approach: the last installment takes up the difference.

## Solution
- **The rule** lives in `Subscription::finalInstallmentAmount()`: last = balance − monthly × (months − 1), in cents.
  - 558.04 in the first example above, 333.34 in the second.
  - `total_subscription_amount` is the balance after the down payment, and the down payment is not one of the installments.
  - If the row lacks the figures, or the result would be ≤ 0, the monthly amount stands.
- **Supporting helpers:** `finalInstallmentAdjustment()` (the difference, or null on an even split), `isNextInstallmentFinal()`, `nextInstallmentAmount()` and `adjustedFinalInstallmentAmount()`.
- **Billing cron** (`SubscriptionService::createInvoiceForSubscription`):
  - On the cycle where `installments_paid = installment_count − 1`, it bills `nextInstallmentAmount()`.
  - When the amount differs from the monthly one, it writes `details` onto the invoice:
    - a line item, "Installment 12 of 12 (final)", with the reason: "This last payment is $0.02 less than your usual $558.06, so your 12 payments add up to exactly $6,696.70."
    - the figures, under `details.final_installment`.
  - The invoice PDF already renders `details.items`.
- **Email** (`emails/invoice/sent.blade.php`): adds a "This is your final installment" note with the same sentence. `Invoice::finalInstallmentNote()` formats it; the text is in `lang/en/invoice.php` (`final_installment.*`).
- **Bell:** a new notification type, `invoice.final_installment`, in `config/notifications.php`. It is sent in place of `invoice.sent` for that invoice and carries the difference, the monthly amount and the total.
- **Subscription details:** `final_installment_amount` is added to `SubscriptionDetailsResource` (admin subscription/billing details), to `BorrowerBillingService` (borrower Billing Details) and to `MySubscriptionService` (partner My Subscription). It is null on an even split.
  - Next billing (`Subscription::nextBilling()`) shows the adjusted amount when the last installment is next.
- **Upcoming Billing list:** the row amount (`BillingResource`), the Amount sort (`billingAmountSql`) and the scheduled total show what the cron will charge.
- **Found on the way:** `BillingService::summarise()` never selected `subscription_end_date`, so every row was skipped. Upcoming Billing's `scheduled_total` and `overdue_count` were therefore always 0. The column is now selected.

## Files changed
- `app/Models/Subscription/Subscription.php`: the final-installment methods; `nextBilling()` uses them.
- `app/Models/Invoice/Invoice.php`: `finalInstallmentNote()`.
- `app/Services/FCH/Subscription/SubscriptionService.php`: the adjusted amount and `finalInstallmentDetails()`.
- `app/Services/FCH/Common/Invoice/InvoiceService.php`: `sendInvoice()` picks `invoice.final_installment`.
- `config/notifications.php`: `invoice.final_installment`.
- `lang/en/invoice.php`: `final_installment.*`.
- `resources/views/emails/invoice/sent.blade.php`: the final-installment note.
- `app/Http/Resources/FCH/Subscription/SubscriptionDetailsResource.php`, `app/Services/Borrower/Billing/BorrowerBillingService.php`, `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`: `final_installment_amount`.
- `app/Http/Resources/FCH/Billing/BillingResource.php`, `app/Services/FCH/Common/Billing/BillingService.php`: the upcoming amount, sort and totals; the missing `subscription_end_date`.

## Testing
- **New:**
  - `tests/Unit/Subscription/FinalInstallmentTest.php` (8)
  - `tests/Unit/Subscription/SubscriptionDetailsFinalInstallmentTest.php` (3)
  - `tests/Feature/Billing/FinalInstallmentBillingTest.php` (10): the cron amount both ways, invoice details, PDF, email, bell, and the earlier and even-split cases.
- **Added to:**
  - `BorrowerBillingApiTest` (2)
  - `PartnerAccountApiTest` (1)
  - `UpcomingBillingSortTest` (1): amount, sort and scheduled total.
- **Each new test failed first.**
- **Full suite:** 4500 tests passed, 2 skipped. After the wording change, the billing, subscription and notification suites were rerun: 268 passed.

## Open items
- **Plans already billed with the old rule:** a plan whose last installment is already paid keeps the extra or missing cents. Nothing is refunded or charged retroactively.
- **Admin edits:** if an admin edits `subscription_amount_per_month` mid-plan, the last installment is computed from the current monthly figure, not from what was actually paid.
- **Frontends:** client-fch and the admin panel show `final_installment_amount`. See their handoffs of the same date.
