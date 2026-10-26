# Capital Access — the admin overview sends the estimate in short form too (BUG-031)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-031** (CP-CAP-04). The estimate stays as calculated (the maximum of every play the client qualifies for, less completed plays, as decided), and both portals now label it "Estimated Remaining Access" and show it short ($1.4M). The borrower overview already sent `estimated_total_access_compact`; the admin's `/common/borrowers/{id}/capital-access-overview` did not.

## Solution
`BorrowerCapitalAccessController::getOverview()` also returns `estimated_total_access_compact` = `formatCompactCurrency($totalExposureMax)`, the same helper and field as `CapitalAccessOverviewController`. Examples: 1,400,000 → $1.4M; 1,365,000 → $1.37M; 77,000 → $77K. `estimated_total_access` is unchanged.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerCapitalAccessController.php`: the compact field.
- `tests/Feature/CreditBuilder/HostCreditBuilderApiTest.php`: +1 test.

## Tests
- **New:** the overview carries `estimated_total_access_compact` equal to `formatCompactCurrency()` of the total (failed first). `HostCreditBuilderApiTest`: 20 passed.

## Open items
- The label and display changes are in the client-fch and admin handoffs of the same date.
- QA sheet: BUG-031 → Fixed (wording and short amount, as decided). The amount is unchanged by design.
