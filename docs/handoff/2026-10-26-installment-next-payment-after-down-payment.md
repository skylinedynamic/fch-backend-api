# Subscriptions — after the down payment, the next amount due is the monthly installment (BUG-126)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-126** (E2E-05): a WL partner on Installment ($7,696.70, $1,000 down, 12 months) paid the down payment. Admin › Subscriptions › details then showed **Next Payment Due 2026-11-21 ($1,000.00)**, the down payment again, while Monthly Installment showed $558.06. The sheet said Fixed; `Subscription::nextBilling()` had not changed since 1 Sep.

`nextBilling()` treated `installments_paid === 0 && down_payment > 0` as "the down payment is due". But `SubscriptionService::handleInstallmentPayment()` deliberately leaves `installments_paid` at 0 when the down payment is paid (it is not one of the installments), so for the whole first month the down payment read as still due. The same method feeds My Subscription (the partner's own page) and the borrower billing page.

The actual charge was right: the cron bills `subscription_amount_per_month` once a down-payment invoice exists. A test now confirms it bills $558.06.

The labels in `nextBilling()` were string literals in the model.

## Solution
- "Down Payment" is due only while **nothing has been paid**, i.e. no `subscription_start_date` (set by the first payment, whatever it was). Afterwards the next amount is the monthly installment.
- Labels moved to `lang/en/subscription.php` › `next_billing.*` (`one_time_enrollment`, `platform_fee`, `down_payment`, `installment`, `seat_fee`); `nextBilling()` uses `__()`. They are display text only; no code compares against them.
- One Time Payment is unchanged: unpaid → One Time Enrollment (full amount); paid → Platform Fee once the 12 months the payment covers run out.

## Files changed
- `app/Models/Subscription/Subscription.php`: `nextBilling()`: the down-payment condition, and the labels from the lang file.
- `lang/en/subscription.php`: `next_billing.*`.
- `tests/Unit/Subscription/NextBillingTest.php`: new, 10 tests.
- `tests/Feature/Billing/SubscriptionBillingCycleTest.php`: +1 test.

## Tests
- **New unit tests (`nextBilling()` in every state):**
  - installment before any payment → Down Payment $1,000;
  - after the down payment → Installment $558.06;
  - no down payment → Installment;
  - some installments paid → Installment;
  - paid off → Platform Fee;
  - one time unpaid → One Time Enrollment; one time paid → Platform Fee;
  - borrower → Installment; seats → Seat Fee;
  - every label exists in the lang file.
- **New feature test:** enrol, pay the down-payment invoice through `handleSuccessfulPayment`, then `nextBilling()` says Installment $558.06, and the cron raises an installment invoice of $558.06.
- All 11 new tests failed first. `--filter "Subscription|Billing|Invoice|Payment"`: 519 passed.

## Open items
- QA sheet: BUG-126 → Fixed after the stage retest. The column labels are in the admin handoff `2026-10-26-partner-subscription-total-and-installment-balance.md`.
