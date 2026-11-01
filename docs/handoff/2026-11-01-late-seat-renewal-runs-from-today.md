# A seat renewal paid late runs from the day it is paid

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
Billing rule (confirmed by the product owner): there is no Overdue. An unpaid invoice locks the profile once its paid-through date passes, and paying a late invoice runs one month **from the day it is paid**. Partners and borrowers follow this (`SubscriptionService::nextCycleFrom()`). User/employee **seat renewals** did not.

`handleUserSeatPayment()` added the months to the old `subscription_end_date`. A seat that lapsed in March and was paid in November ran to April, still in the past. The user stayed locked out after paying, and the cron raised the next month at once, and the month after.

## Solution
Seat renewals use `nextCycleFrom()`, which now takes the number of months (`month_count` on the invoice):
- paid early: added to the current paid-through date, so no paid days are lost;
- paid late: counted from today.

The first activation of a seat is unchanged (it already runs from today).

## Files changed
- `app/Services/FCH/Subscription/SubscriptionService.php`: `nextCycleFrom(Subscription, int $months = 1)`; the seat renewal branch of `handleUserSeatPayment()` uses it.
- `tests/Feature/User/PaidSeatUserActivationTest.php`: 3 renewal tests. Paid late, it runs from today. Paid early, it extends the paid-through date. Paid late for 3 months, it runs 3 months from today.

## Testing
- The two "paid late" tests failed before the fix (the old date plus the months), then passed. The "paid early" test passed before and after.
- `tests/Feature/{User,Billing,Subscription,Payment,Employee}`, `tests/Integration/{Subscription,Payment}`, `tests/Unit/Subscription`: 411 passed.
- Full fch-api suite (with the same day's other payment fixes): 4300 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- Seats already renewed late keep their old (past) date until their next payment.
