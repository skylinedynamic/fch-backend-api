# QA round 1 - batch 2 (BUG-009, 011, 013, 016, 022, 026, 027, 036)

**Date:** 2026-10-20
**Branch:** `bugfix/qa-round1-release` (from `release`; first cut from `stage` as `bugfix/qa-round1-batch-2`, moved 2026-10-21 - no file differed between the two) in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react` - not committed
**Author:** arafat-invento (with Claude Code)
**Source:** `public/Four_Corner_Regression_Test_Cases_Round1 (3).xlsx`, Defect Log

## Problem
| Bug | Portal / module | What QA saw | Root cause |
|---|---|---|---|
| BUG-009 | Owner: Change Requests, Request Credit Profile, Embedded Form > Loan Applications; Partner: Upcoming Billings; Client: Funding Requests | Sort arrow changes, rows don't | Change Requests: controller dropped the sort. Loan Applications: always newest first. Upcoming Billings Name: sorted on the partner's name only, NULL for client rows (and the join overwrote row id/status). Credit Profile: already fixed 15-Oct. Client Funding Requests: not reproducible on this build. |
| BUG-011 | Owner + Partner: Subscriptions | NAME / ENTITY TYPE sort -> error toasts | `subscriber_name` is not a column (SQL error on MySQL) |
| BUG-013 | Owner: Dashboard | Recent Funding Applications misses the newest | Host read only its own partner record's applications |
| BUG-016 | Owner: Leads > Lead Profile | Same counters on every lead | Page showed `/common/stats` (the user's partner stats) |
| BUG-022 | Owner: CMS Site Settings, Settings > MicroBilt (also Credit Profile) | Secrets in the page | APIs returned secret values in full |
| BUG-026 | Client: Funding | Card label != detail label | Card hardcoded "Likely Eligible"; detail used the engine label |
| BUG-027 | Client: My Applications, Dashboard | Started (On Hold) application missing / count 0 | On Hold excluded from list and count |
| BUG-036 | Client: Pre Qualification | Previous does nothing on Business Details | Going back validated and saved the step first |

## Solution
See `docs/test-findings.md` F132-F139. Behaviour changes a user will notice:
- Subscriptions / Upcoming Billings Name sort orders by the name shown (partner, client, seat).
- Owner dashboard widget shows the newest 5 across all partners for the host.
- Lead Profile no longer shows the counter strip.
- Secret fields show blank with "Saved - leave blank to keep"; leaving them blank keeps the secret.
- Borrower: one eligibility label on card and detail; On Hold applications listed with "Continue Application"; Previous always goes back.

## Files changed (API)
- `app/Http/Controllers/Api/V1/FCH/Admin/IntakeReview/ChangeRequest/ChangeRequestController.php` - pass sort through
- `app/Services/FCH/Common/ChangeRequest/ChangeRequestService.php` - Borrower column sort, order normalised
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`, `app/Http/Requests/.../FetchEmbedFormSubmissionsRequest.php` - whitelisted sort
- `app/Models/Subscription/Subscription.php` - `scopeOrderBySubscriberName()`
- `app/Services/FCH/Subscription/SubscriptionService.php` - Name / Entity Type sort
- `app/Services/FCH/Common/Billing/BillingService.php` - Name sort without join
- `app/Services/FCH/Admin/Dashboard/DashboardService.php` - host widget across partners
- `app/Models/Common/AppConfiguration.php` - `SECRET_MASK`, `isSecretKey`, `forDisplay`, `maskSecrets`, `keepsStoredSecret`
- `app/Services/FCH/Admin/CMS/SiteSettingsService.php`, `app/Services/FCH/Common/Setting/AppConfigurationService.php`, `app/Http/Controllers/Api/V1/FCH/Common/Setting/AppConfigurationController.php` - mask on read, keep on blank
- `app/Services/Borrower/Application/MyApplicationService.php`, `app/Services/Borrower/Dashboard/DashboardService.php` - include On Hold

Admin and borrower files: see their `docs/handoff/2026-10-20-qa-round1-batch-2.md`.

## Tests
- Added: `tests/Feature/Subscription/SubscriptionListSortTest.php`, `tests/Feature/Billing/UpcomingBillingSortTest.php`, `tests/Feature/Dashboard/RecentFundingApplicationsWidgetTest.php`, `tests/Unit/Setting/AppConfigurationSecretMaskTest.php`
- Added cases: `ChangeRequestReviewApiTest`, `EmbedFormSubmissionsApiTest`, `CreditProfileRequestListOrderTest`, `BorrowerFundingRequestApiTest`, `CmsSeoAndSiteSettingsAdminApiTest`, `AppAndOpenAiConfigurationApiTest`, `BorrowerDashboardAmountsTest`
- Updated: `MyApplicationsApiTest` - On Hold is now listed (was asserted hidden)
- Suites run: full `php artisan test` - 2925 passed, 2 skipped, 0 failed

## Open / follow-up
- BUG-009 client Funding Requests descending: not reproducible on `stage` code (API + UI tests pass) - QA to retest after deploy.
- The Subscriptions ENTITY TYPE error could not be reproduced on SQLite; it is now ordered on the qualified column - QA to confirm on MySQL staging.
- O63-O67 in `docs/test-findings.md`.
- Borrower e2e (Playwright) is live against the local API/database and was not run (project rule: no tests against the `.env` database).

## Addendum 2026-10-21 - xlsx (4): BUG-003, BUG-007, BUG-008
- **BUG-008** Owner > Intake & Review > Funding Request: FUNDING SEQUENCE sorted by `funding_sequence_id`; now by the sequence name (`app/Services/FCH/Common/FundingRequest/FundingRequestService.php`, F140). Test: `FundingRequestReviewApiTest::the_list_sorts_by_the_funding_sequence_name_both_ways`.
- **BUG-003** and **BUG-007** are admin-portal only (F141, F142) - see the admin handoff.
- Branch moved to `bugfix/qa-round1-release` from `origin/release` (release had only the stage-merge commit extra; none of the touched files differed).
- Follow-up (F143): every column of Intake & Review > Funding Request checked - ASSIGNED LENDER now sorts (it did not), BORROWER NAME sorts by first then last name, a tie-breaker keeps equal values in one order across pages, and the search finds the assigned lender and "$300,000". `app/Services/FCH/Common/FundingRequest/FundingRequestService.php`, `app/Models/FundingRequest/FundingRequest.php` (search scope). Test: `tests/Feature/FundingRequest/FundingRequestListSortSearchTest.php`.
- Follow-up (F144): Intake & Review > Funding Application - DATE now sorts (`date` -> `created_at`), and the Date Range filter works on the viewer's own days in any timezone (it was ignored). `RequestApplicationController.php`, `RequestApplicationService.php`; admin `toLocalYmd()`. Test: `tests/Feature/Application/FundingApplicationListSortTest.php`. Open: O68.
