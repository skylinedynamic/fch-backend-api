# Upcoming Billings ordered by date, soonest first (BUG-012)

Date: 2026-10-26

## Problem
Owner › Upcoming Billings: sorting the Amount column gave a non-numeric looking order ($4 / $250 / $250 / $1). The SQL sort read different columns from the amount the row shows (`BillingResource`: a down payment first in the enrollment phase, $0 once completed). It was decided the list goes by upcoming billing date instead, the soonest charge first, and Amount does not sort.

## Solution
- The date sort puts rows without a date (subscribers awaiting a first payment) after every dated row; MySQL would list NULLs first on ascending.
- The admin list loads by date ascending and its Amount column is no longer sortable (admin handoff of the same name).

## Files changed
- `app/Services/FCH/Common/Billing/BillingService.php`: undated rows last on the date sort.
- `tests/Feature/Billing/UpcomingBillingSortTest.php`: new case, soonest first and undated last; `rows()` takes the sort field.

## Testing
- The new case failed first (the undated row came first), then passed.
- `--filter "Billing|Subscription"`: 131/131 passed.

## Open items
- The `upcoming_billing_amount` sort case in `BillingService` is no longer used by the admin screen; it still does not match the amount shown, should Amount sorting come back.
