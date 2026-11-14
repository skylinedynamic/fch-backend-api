# Intake & Review: Partner Name column and Partner filter (2026-11-11)

Repos: **fch-backend-api** and **Fch-admin-frontend-react**, branch `bugfix/qa-round1-run2`.

## Problem
On Host > Intake & Review the host could not tell or narrow whose client a row was:
- **Funding Application** had no PARTNER NAME column and no partner filter.
- **Funding Request** showed PARTNER NAME, but its column had no search and there was no Partner dropdown. The page also never sent its column filters to the API.

## Solution
- **API** (`/common/request-application/funding-application`, `/common/funding-request`):
  - `partner_id` request field (validated integer): only that partner's rows.
  - `filters.partner_name`: the partner's name or company contains the text (`Partner::scopeNamed`), read with the new `QueryFilter::value()`.
  - Funding application rows carry `partner_name` (only when the partner is loaded, so the borrower's own list is unchanged) and sort by `partner_name`.
  - A partner's team is still limited to its own rows, so neither filter reaches another partner's data.
  - On the application list, `partner_name` is removed from the generic column filters: `QueryFilter::apply()` does not check that a field is a real column and would add `WHERE partner_name LIKE` (an SQL error on MySQL).
- **Admin** (host only):
  - Partner dropdown ("All Partners", searchable, every partner from `/common/get-all-partners`) on both pages; Clear resets it.
  - Funding Application: new PARTNER NAME column after COMPANY NAME, sortable and searchable.
  - Funding Request: PARTNER NAME column searchable; the page now sends `filters`.

## Files changed
- API: `Models/Partner/Partner.php`, `Services/Global/Datatable/QueryFilter.php`, `Services/FCH/Common/Application/RequestApplicationService.php`, `Services/FCH/Common/FundingRequest/FundingRequestService.php`, `Http/Controllers/.../Application/RequestApplicationController.php`, `Http/Requests/.../Application/FetchRequestApplicationRequest.php`, `Http/Requests/.../FundingRequest/FetchFundingRequestRequest.php`, `Http/Resources/Borrower/MyApplication/FundingApplicationResource.php`
- Admin: `pages/applications/funding/FundingApplication.tsx`, `pages/applications/funding-request/FundingSequence.tsx`, `modules/application/types.ts`

## Tests
- API: `tests/Feature/Application/IntakeReviewPartnerFilterTest.php` (10: name on rows, dropdown, column search by name/company, sort, combined filters, invalid id, partner cannot reach another partner), `tests/Unit/Services/QueryFilterTest.php` (`value()`, 8 cases).
- Admin: `tests/pages/applications/funding/FundingApplication.partnerFilter.test.tsx` (5), `tests/pages/applications/funding-request/FundingSequence.partnerFilter.test.tsx` (4).

## Left open
- Nothing. Pre-existing: the Funding Request STATUS column filter is set up in the page but its column has no filter UI.
