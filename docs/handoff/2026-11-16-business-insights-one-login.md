# Business Insights: weekly email links to the new page path

2026-11-16

## Problem
The admin panel moved Business Insights from `/owner-summary` to `/business-insights`, and for now shows it to one login only (`developer@fch.com`). The weekly owner report email still linked to `/owner-summary`.

## Solution
- `OwnerReportPresenter::dashboardUrl()` now builds `/business-insights`, so the email's "Open Owner Summary" button opens the new path.
- **No API access change.** The email allow-list lives only in the admin panel for now (by request: "api te check dorkar nai apatoto"). The API still admits the host owner (`CommissionViewer::HOST_OWNER`) as before. An email gate (`OwnerSummaryAccess`) was written and then removed in the same task; nothing of it remains.
- The old `/owner-summary` path is gone with no redirect. The weekly report was never deployed (not on `release`/`stage`/`master`), so no real email carries the old link.

The admin panel side: `react-fch/docs/handoff/2026-11-16-business-insights-one-login.md`.

## Files changed
- `app/Services/FCH/Common/OwnerSummary/OwnerReportPresenter.php`: `dashboardUrl()` is now `/business-insights`.
- `tests/Unit/OwnerSummary/OwnerReportPresenterTest.php`: expects `https://portal.test/business-insights`.
- `tests/Integration/OwnerSummary/WeeklyReportCommandTest.php`: the email contains `/business-insights`.

## Testing
- Tests changed first and run: 2 failed (red), as expected.
- After the fix: `tests/Feature/OwnerSummary` + `tests/Unit/OwnerSummary` + `tests/Integration/OwnerSummary` 116 passed.

## Open items
- **The API does not check the email.** Any host owner who calls `/api/v1/common/owner-summary` (and its export and report settings) directly still gets the data; only the admin panel hides the screen. Add the check here when it needs to be enforced.
- The email button text still says "Open Owner Summary".
