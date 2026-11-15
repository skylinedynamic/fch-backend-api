# R3-BUG-034: a dashboard card rounds an amount up ($1,499 shows as "$1.5K")

Date: 2026-11-16

## Problem

QA (R3-CP-DASH-01-R2) set client 216's funded amount to several values. The client Dashboard's "Total Funded Amount" card, and the API field `states.total_funded_amount_formatted`, showed:
- $1,499 as "$1.5K"
- $999,999 as "$1M"

`formatCompactCurrency()` rounded to three significant figures. So a card could show more than the actual amount.

QA's expected result: at most one decimal, and no rounding up.

## Solution

`formatCompactCurrency()` now **cuts instead of rounding**, with at most one decimal:

| Amount | Now |
|---|---|
| $999 | "$999" |
| $1,000 | "$1K" |
| $1,499 | "$1.4K" |
| $1,500 | "$1.5K" |
| $999,999 | "$999.9K" |
| $4,326,000 | "$4.3M" |
| $1,250,000,000 | "$1.2B" |

Other rules:
- Cents are cut too: $950.60 shows as "$950".
- Negative amounts are cut towards zero: -$1,499 shows as "-$1.4K".
- The calculation uses whole dollars and tenths as integers, so a float error cannot push a value over a boundary.

The same helper also feeds Capital Access's `estimated_total_access_compact`, in both the client portal and the admin portal. Those amounts follow the same rule now.

## Files changed

- `app/Helpers/helpers.php`: `formatCompactCurrency()` rewritten to cut to one decimal.
- `tests/Unit/Helpers/FormatCompactCurrencyTest.php`: expectations moved to the new rule. New cases: $1,499, -$1,499, and $999,999.
- `tests/Feature/Borrower/BorrowerDashboardAmountsTest.php`: funded total is now "$4.3M" (was "$4.33M").
- `client-fch/tests/components/CapitalAccess/EstimatedTotalAccessCard.test.tsx`: fixture moved to the API's new short form ("$1.3M").
- `Fch-admin-frontend-react/tests/pages/clients/components/CapitalAccessSection.access.test.tsx`: same fixture change.

## Testing

- **Seen failing first:** 11 of 23 formatter cases.
- **After the fix:**
  - formatter: 23 passed
  - `BorrowerDashboardAmountsTest`: 3 passed
  - `tests/Feature/CreditBuilder`: 134 passed
  - client-fch Vitest (dashboard, capital access): 115 passed
  - admin `CapitalAccessSection.access`: 1 passed
- **Full suite** (with BUG-031 and BUG-032): 5,729 tests, all passed (2 skipped).

## Open items

- R3-BUG-034 is assigned to Arafat in the defect log. Fixed here at the user's request.
- QA should retest the values from the bug on stage, and update the sheet.
