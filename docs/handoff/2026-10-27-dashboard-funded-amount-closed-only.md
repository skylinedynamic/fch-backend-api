# Client dashboard: Total Funded Amount counts closed applications only (BUG-069)

Date: 2026-10-27

## Problem
QA reported that Total Requested Amount on the client dashboard (`GET /borrower/dashboard`, `data.states`) did not follow funding requests. It was agreed that the four cards are defined as follows:
- **Funding Applications:** count of funding applications. Unchanged.
- **Total Funding Requests:** count of funding requests, canceled excluded. Unchanged.
- **Total Requested Amount:** sum of the funding applications' requested amount. Unchanged; this is what it already did.
- **Total Funded Amount:** sum of `total_funded_amount` over funding applications that are **Closed (Completed)** only. It used to add up every application, including ones still in progress.

## Solution
`DashboardService::getStats()` now filters `total_funded_amount` to `FundingApplication::CLOSED_COMPLETED`. The other three values are unchanged.

## Files changed
- `app/Services/Borrower/Dashboard/DashboardService.php`: funded total limited to Closed (Completed) applications.
- `tests/Feature/Borrower/BorrowerDashboardAmountsTest.php`:
  - New case: requested counts every application, funded counts only Closed (Completed).
  - The formatting case's fixtures are now Closed (Completed), so their funded amounts still count.

## Testing
- The new case failed first, then passed.
- `--filter "Dashboard|Borrower"`: 906 passed, 1 failed. The failure is the pre-existing `NavigationMapTest` capital_access case (the client route moved to `/credit-card-stacking`).

## Open items
- None for the API. The client cards read these values unchanged.
