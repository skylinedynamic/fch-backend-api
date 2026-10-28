# BUG-136: an On Hold application shows its funding request's amount, type and purpose

**Date:** 2026-10-28
**Branch:** bugfix/qa-round1-release-v2

## Problem
A client starts a funding application from an approved funding request and saves it before the Funding step (status On Hold). The application row has no `requested_amount`, `preferred_funding_type` or `purpose_of_funds` yet, so it showed:
- Admin > Clients > Funding Applications: Requested, Funding Type and Purpose as "—"
- Client > My Applications: Funding Type and Requested Amount as "N/A"
- Client dashboard: the latest-applications row had no amount, and the Total Requested card did not count it ($0)

The funding request it was started from already had those values ($40,000 / Revenue Based Financing).

## Solution
`FundingApplication` gets `requestedAmountOrRequest()`, `fundingTypeOrRequest()` and `purposeOfFundsOrRequest()`. Each returns the application's own value, or the linked funding request's `requested_amount` / `funding_type` / `use_of_funds` while the application's own value is empty. This is display only: the stored columns are not changed. It is the same fallback the borrower wizard already uses (`Borrower/FundApplication/FundingRequestResource`).

They are used by:
- `FCH/Common/Application/FundingApplicationResource` (admin list and details)
- `Borrower/MyApplication/FundingApplicationResource` (My Applications)
- `Borrower/Dashboard/DashboardFundingApplicationResource`, plus the client dashboard's `total_requested_amount`

The lists eager-load `fundingRequest`, so there is no extra query per row. `requested_amount_original` (the raw value for the edit form) is still the application's own value, so the edit form is unchanged. The admin dashboard already leaves On Hold applications out, so it needed no change.

## Files changed
- `app/Models/Borrower/FundingApplication.php`: the three helpers
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`
- `app/Http/Resources/Borrower/MyApplication/FundingApplicationResource.php`
- `app/Http/Resources/Borrower/Dashboard/DashboardFundingApplicationResource.php`
- `app/Services/FCH/Common/Application/RequestApplicationService.php`: eager-loads `fundingRequest`
- `app/Services/Borrower/Application/MyApplicationService.php`: eager-loads `fundingRequest`
- `app/Services/Borrower/Dashboard/DashboardService.php`: eager-loads `fundingRequest`; the total counts the fallback amount

## Tests
- Added: `tests/Feature/FundApplication/OnHoldApplicationShowsItsFundingRequestTest.php` (Defect). It covers the admin list, admin details, My Applications and the client dashboard (row and total), checks that the application's own values win once filled in, and checks that an application with no funding request still shows nothing.
- Suites run: Feature `FundApplication`, `Borrower`, `Application`, `Dashboard`, `Funding`: 470 passed, 1 skipped. Full suite: see the PR.

## Open / follow-up
- Sorting the admin list by Requested or Purpose still sorts by the application's own column, so an On Hold row sorts as empty.
