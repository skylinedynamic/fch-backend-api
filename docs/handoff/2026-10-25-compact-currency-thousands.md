# Compact amounts in thousands, and a compact Total Est. Access

**Date:** 2026-10-25
**Branch:** `feature/new-walk-through`
**Author:** Awlad Hossain

## Problem
- The borrower dashboard hero showed **Total Est. Access** in full ("$920,000.00"), unlike the top cards (Total Requested / Funded Amount), which use `formatCompactCurrency`.
- That helper only shortened amounts from $1M up, so anything under a million stayed long ("$920,000").
- It also dropped zeros that weren't decimals: $920,000 came out as "$92K" once thousands were added, and $370,000,000 already showed as "$37M".

## Solution
- **`formatCompactCurrency`** gives whole dollars under $1,000 ("$950"), then three significant figures in thousands, millions or billions:
  - $1.5K, $12.3K, $920K, $4.33M, $37.2M, $1.25B
  - Rounding up rolls into the next unit: $999,999 is "$1M" and $999.60 is "$1K", never "$1000K".
  - Trailing zeros are only trimmed after a decimal point.
- **`GET /borrower/capital-access/overview`** also returns `estimated_total_access_compact`, from the same helper. `estimated_total_access` stays in full for the Credit Card Stacking page.
- The dashboard's `total_requested_amount_formatted` and `total_funded_amount_formatted` use the same helper, so they now read "$45K" where they read "$45,000".

## Files changed
- `app/Helpers/helpers.php`: `formatCompactCurrency` handles thousands and keeps zeros that aren't decimals.
- `app/Http/Controllers/Api/V1/Borrower/CapitalAccess/CapitalAccessOverviewController.php`: adds `estimated_total_access_compact`.
- `app/Services/Borrower/Dashboard/DashboardService.php`: comment only.

## Tests
- `tests/Unit/Helpers/FormatCompactCurrencyTest.php`:
  - Thousands cases, the rollovers into $1K and $1M, and negative thousands.
  - `$370M` keeps its zero; this one failed before the fix.
- `tests/Feature/CreditBuilder/BorrowerCreditBuilderApiTest.php`: the overview returns the amount in full and compact.
- Ran Unit, Feature/Borrower and Feature/CreditBuilder: 1155 passed, 1 skipped, 9 failed. All 9 failures are `NavigationMapTest`, which reads the sibling portals' route files and fails the same way without these changes.

## Open / follow-up
- borrower-fch reads `estimated_total_access_compact` on the dashboard hero (see its handoff `2026-10-25-walk-through-redesigns.md`).
