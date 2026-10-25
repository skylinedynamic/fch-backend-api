# QA round 1 - batch 3 (14 bugs: BUG-003, 007, 008, 009, 011, 013, 025, 026, 027, 036, 037, 048, 049, 050)

**Date:** 2026-10-24
**Branch:** `bugfix/qa-round1-release` in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react` - not committed
**Author:** arafat-invento (with Claude Code)
**Source:** `public/Four_Corner_Regression_Test_Cases_Round1 (4).xlsx`, Defect Log

## Problem
Nine of the 14 were fixed in batch 2 (`2026-10-20-qa-round1-batch-2.md`, committed) and are still "In Progress" in the sheet; five were "New".

| Bug | Where | What QA saw | Root cause |
|---|---|---|---|
| BUG-048 | Owner: Intake & Review > Funding Application | Status filter does nothing; options are pre-qualification statuses | Admin used `APPLICATION_STATUS_OPTIONS`; API never applied `status` |
| BUG-025 | Client: Funding > option detail | "1-5 Days Days" | " Days" appended to free text that has its own unit |
| BUG-037 | Client: Pre Qualification > Business Details | Time in Business read-only, Next blocked | Start date could only be set in the profile |
| BUG-050 | Owner: Lenders > Product Category | Raw key "lender.category_name_unique" | Translation key never defined |
| BUG-049 | Owner: Funding Sequence / Lenders - manage groups/categories | Rename toast says "added" | Not reproducible in code (see Open) |
| BUG-009 (rest) | Client: Funding Requests | Second (descending) click keeps the order | APPLICATION STATUS column was not sortable on the API |

## Solution
- **BUG-048**: `RequestApplicationService::getFundingApplicationList()` filters on `funding_applications.status`; admin filter lists `FUNDING_APPLICATION_STATUS_OPTIONS` (F145).
- **BUG-025**: `formatSpeedToFunds()` in both frontends (F146).
- **BUG-037**: `BusinessInfoRequest` takes optional `business_start_date` (not in the future); `years_in_business` is required only without it. `PreQualificationService::businessInfo()` saves the date to the borrower **only when the profile has none**, and always derives `years_in_business` from the borrower's start date. `PreQualificationResource` returns `business_start_date`. Borrower step shows a date picker when none is on file (F147).
- **BUG-050**: `lender.category_name_unique` added; no-op `'default'` removed (F148).
- **BUG-009 follow-up**: borrower funding-request list sorts `application_status` by lifecycle key; `FundingApplication::statusKeyCase()` extracted from `scopeOrderByStatusKey()` (F149).
- **BUG-003 regression** (admin, F150): the 21-Oct `release` merge brought "Null" back on the Leads list; fixed again in `LeadTableCells.tsx`.
- Re-checked against the sheet (no new change needed): 007, 008, 011, 013, 026, 027, 036 and the other BUG-009 screens - see batch 2. For BUG-026 the card and detail both use `FundingQualificationService::evaluateReadinessFactors`.

## Files changed (API)
- `app/Services/FCH/Common/Application/RequestApplicationService.php` - status filter
- `app/Http/Requests/Api/V1/Borrower/PreQualification/BusinessInfoRequest.php` - `business_start_date`
- `app/Services/Borrower/PreQualification/PreQualificationService.php` - save start date once, derive time in business
- `app/Http/Resources/Borrower/PreQualification/PreQualificationResource.php` - `business_start_date`
- `lang/en/borrower_validation.php`, `lang/en/lender.php` - messages
- `app/Http/Requests/Api/V1/FCH/Admin/Lender/{Store,Update}LenderProductCategoryRequest.php` - key
- `app/Models/Borrower/FundingApplication.php` - `statusKeyCase()`
- `app/Services/Borrower/Funding/FundingRequestService.php` - Application Status sort
- `docs/test-findings.md` - F145-F149, O69

Admin and borrower files: their `docs/handoff/2026-10-24-qa-round1-batch-3.md`.

## Tests
- Added: `FundingApplicationListSortTest::the_status_filter_keeps_only_applications_in_that_status`, `BorrowerPreQualificationWizardApiTest` (3 start-date cases), `FundingCatalogueAdminApiTest::duplicate_names_and_edits_get_readable_messages`, `BorrowerFundingRequestApiTest::my_requests_sort_by_application_status_both_ways`
- Suites run: full `php artisan test` - see the report for this branch

## Open / follow-up
- O69 (BUG-049): QA to retest; if seen again, record the exact clicks.
- BUG-011 ENTITY TYPE and the SQL in F149 are MySQL paths tested on SQLite - QA to confirm on staging.
- Borrower e2e not run: it signs in to the live local API/database.
