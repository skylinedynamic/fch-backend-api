# Upcoming Billings: Amount and Outstanding sort on the figures shown (BUG-012, reopened)

Date: 2026-10-29

## Problem
Owner › Upcoming Billings. BUG-012 was first closed by removing the Amount sort (`2026-10-26-upcoming-billings-date-order.md`). The QA retest on 28-Oct reopened it, because Amount should sort as numbers. Outstanding had no sort either.

The cause was still in `BillingService::fetchUpcoming()`. The `upcoming_billing_amount` case sorted on its own `CASE` (one column per phase, otherwise `payable_amount`), not on the amount `BillingResource` shows:
- An enrolling client's down payment sorted as their payable amount.
- A completed row sorted as `payable_amount` instead of $0.

The order matched nothing on screen, for example $1,000 placed before $50. `outstanding_amount` had no sort case, so it fell to the default (subscription id).

## Solution
- **Amount:** `billingAmountSql()` is the resource's amount logic as SQL. The phase column for installment, platform fee and user seat; 0 once completed; in enrollment, the down payment while one is set and no installment is paid; otherwise per-month, then payable, then 0. The phase names are bound, not concatenated.
  - The columns are `decimal:2` strings, so the resource's `?:` only falls through on NULL. A stored `0.00` is shown as $0. `COALESCE` matches that, and so does the cron, which uses `??`.
- **Outstanding:** a new `outstanding_amount` sort orders by a subquery that totals the unpaid invoices' `payment_amount`. It is built on `Invoice::query()`, so soft-deleted invoices are left out, as in the `unpaidInvoices` relation the row's figure comes from.
- The date sort (soonest first, undated last) is unchanged and is still the default.

## Files changed
- `app/Services/FCH/Common/Billing/BillingService.php`: Amount sort uses `billingAmountSql()`; new `outstanding_amount` sort; `Invoice` import.
- `tests/Feature/Billing/UpcomingBillingSortTest.php`: two new cases and an `invoice()` helper.

## Testing
- New `amount_sorts_on_the_amount_each_row_shows`: rows in platform-fee, installment, enrollment-with-down-payment and enrollment-falling-through-to-payable phases, ascending and descending.
- New `outstanding_sorts_on_the_unpaid_total_each_row_shows`: unpaid totals, with a paid invoice and a soft-deleted one that must not count.
- Both failed first, for the right reason (values right, order wrong: `[1000, 50, 300, 6997]` and `[0, 2700, 999]`), then passed.
- `UpcomingBillingSortTest`: 6/6. `--filter "Billing|Invoice|Subscription|PartnerAccountApi"`: 278/278 passed.

## Open items
- `billingAmountSql()` and `BillingResource`'s amount `match` must change together. A comment on each side points to the other.
- The resource's comment says `?:` lets a `0.00` column fall through. With the `decimal:2` cast it does not, and nothing changed here, because the cron agrees with what is shown. Worth a look if a $0 amount ever shows where a fall-through was meant.
- The Amount column filter (`LIKE` on the phase columns) still reads its own columns, not the amount shown. It was not part of this report.
