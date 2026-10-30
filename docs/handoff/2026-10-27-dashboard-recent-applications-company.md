# Admin dashboard: company name on Recent Funding Applications

**Date:** 2026-10-27
**Branch:** feature/new-walk-through
**Author:** Awlad Hossain

## Problem
The admin dashboard's Recent Funding Applications (`GET /common/dashboard` → `latest_funding_applications`) sent the borrower's name and the entity type, but not the company. One person can apply for several companies, and staff once emailed the wrong one. The admin panel now shows Company Name in that table, so it needs the value.

## Solution
`App\Http\Resources\FCH\Common\Dashboard\DashboardFundingApplicationResource` also sends `business_name`: the company stored on that application, not the one on the borrower's profile. The change only adds a field; `entity_type` is still sent.

## Files changed
- `app/Http/Resources/FCH/Common/Dashboard/DashboardFundingApplicationResource.php`: `business_name`

## Tests
- Added `tests/Feature/General/AdminDashboardRecentApplicationsCompanyTest.php`: a host admin's dashboard lists the same person's two applications, each with its own company, and still sends entity type. Failed before the change.
- Run: with `BorrowerCommonRouteAccessTest`, 48/48.

## Open items
- None for this change. Admin side: `Fch-admin-frontend-react` handoff `2026-10-27-funding-applications-company-name-column.md`.
