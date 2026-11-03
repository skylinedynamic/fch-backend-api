# Edit a funding application without a funding request; log embed form conversions

**Date:** 2026-11-03
**Branch:** `bugfix/embed-form-application-edit` (from `release`) in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react`
**Author:** arafat-invento (with Claude)

## Problem
Embedded Form > Loan Applications > Create Client & Funding Application creates a funding
application with no funding request (`funding_request_id` and `funding_sequence_id` null).

- Admin `intake-review/funding-application/{id}` showed no **Edit Funding Application** button: it
  required an approved funding request. The edit wizard (`/fund-application/{funding_request_id}`)
  read every step by funding request, so it could not open such an application either.
- Saving a step with only the application `id` reused that id as a funding request id (404, or
  attached request #id when the borrower had one); failing that it attached the borrower's latest
  approved request.
- The partner step resource guessed a funding request from `auth()->user()->entity_id`, which for a
  partner login is the partner's id - it could show (and the wizard save back) another borrower's
  request id.
- The borrower portal had the same Edit rule and the same resource guess.
- The conversion wrote no activity entry: the application's Activity panel read "No activity found".

## Solution
**Rule:** an application is editable whenever its status is in `config('funding_application.statuses.editable')`
(`is_editable`), however it was created - from a funding request or converted from the embed form.

API
- `PartnerFundingApplicationService::getFundingInfo` / borrower `FundingApplicationService::getFundingInfo`:
  read by `funding_application_id` when given (partner: `visibleTo`, 404 otherwise; borrower: own only).
- `PartnerFundingApplicationService::saveFundingApplication`: a save of an existing application keeps
  its funding request and sequence (none stays none); `request('id')` is never read as a funding
  request id; the latest-approved-request fallback applies only to a new application. (The borrower
  save already did this.)
- Both `FundingRequestResource`s (partner and borrower) guess a funding request only when there is no application.
- `FundingApplicationAuditLogger::logCreatedFromEmbedForm`, called from `EmbedFormService::convertToBorrower`
  inside the conversion transaction: event "Successfully Converted to Funding Application", e.g.
  "Embedded form submission #6 successfully converted to Funding Application FUND-0002323 by Host Admin
  for new client ...". Properties: `source: embed_form`, `embed_form_submission_id`, `is_existing_client`.

Admin portal
- Details page: Edit shows for the host (any) or a white-label/certified partner (own borrowers) when
  `is_editable`; it opens `/intake-review/funding-application/{id}/edit` (new route, same
  `view funding application` guard), always by application id.
- Wizard (`FundApplication.tsx`) reads `applicationId` from the route and passes it to every step;
  hooks/services send `funding_application_id` (it wins over `funding_request_id`). Saves are unchanged:
  they already send the application `id`. The edit-confirmation dialog works as before
  (`edit_needs_confirmation` - shown when the application is signed, e.g. a converted one).

Borrower portal
- My Applications details: Edit shows when `is_editable`, opens `/my-applications/funding/{id}/edit`
  (new route). On Hold "continue" opens the same route. Wizard/steps/hooks as in the admin portal.

Host lock (F171, decision 2026-11-03, replaces 2026-10-29)
- The host's team no longer edits past the editable statuses. `FundingApplication::isEditableBy` is
  the status rule only, for every user. Application 17 (Closed (Completed)) now shows
  "Can't Edit Funding Application" to the host too; a save there is 423 and logged as "Edit Blocked".

## Files changed
API
- `app/Services/FCH/Common/PartnerFundApplication/PartnerFundingApplicationService.php` — read by application id; save keeps the application's request
- `app/Services/Borrower/FundApplication/FundingApplicationService.php` — read by application id
- `app/Http/Resources/FCH/Partner/FundApplication/FundingRequestResource.php` — no request guess for an application
- `app/Http/Resources/Borrower/FundApplication/FundingRequestResource.php` — same
- `app/Services/FCH/Common/Application/FundingApplicationAuditLogger.php` — `logCreatedFromEmbedForm`, `EVENT_CONVERTED_FROM_EMBED_FORM`
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php` — logs the conversion
- `app/Models/Borrower/FundingApplication.php` — `isEditableBy` / `invalidatesSignatureOnEditBy` without the host bypass
- Comments only: `FundingApplicationResource`, partner `FundingRequestResource`, `FundingApplicationEditGuard`, `PartnerFundingApplicationService`
- `docs/test-findings.md` — F169, F170, F171, O74, O75

Admin (`Fch-admin-frontend-react`)
- `src/App.jsx` — `intake-review/funding-application/:applicationId/edit`
- `src/pages/applications/funding/FundingApplicationDetails.tsx` — Edit rule and link
- `src/pages/applications/fundapplication/FundApplication.tsx`, `components/steps/*Step.tsx` — `fundingApplicationId`
- `src/modules/funding-application/services.ts`, `queries.ts` — `funding_application_id` lookup

Borrower (`fch-borrower-react`)
- `src/App.jsx` — `my-applications/funding/:applicationId/edit`
- `src/pages/myapplication/FundingApplicationDetailPage.tsx`, `components/FundingApplications.tsx` — Edit rule and links
- `src/pages/fundapplication/FundApplication.tsx`, `components/steps/*Step.tsx` — `fundingApplicationId`
- `src/modules/funding-application/services.ts`, `queries.ts` — `funding_application_id` lookup

## Tests
- Added: `tests/Feature/FundApplication/PartnerEditsApplicationWithoutFundingRequestTest.php` — read by application id (all steps), no guessed request, other partner 404, save keeps null request (also when a request with the application's id exists), request-based read unchanged
- Added: `tests/Feature/FundApplication/BorrowerEditsApplicationWithoutFundingRequestTest.php` — same for the borrower portal
- Added: `tests/Feature/EmbedForm/EmbedFormConversionActivityLogTest.php` — one conversion entry, new and existing client
- Renamed/updated: `HostEditsAnyFundingApplicationTest` → `HostEditsOnlyEditableFundingApplicationsTest` — host gets 423 past the editable statuses, still edits (and the borrower re-signs) at Submitted / Approved (Final)
- Updated: `tests/Feature/Application/FundingApplicationDetailLockedReasonTest.php` — the host is told a locked application cannot be edited, like everyone else
- Suites run: full `php artisan test` — 4333 passed, 2 skipped. Both frontends: `tsc --noEmit` clean for the changed files (neither has tests for these pages).

## Open / follow-up
- O74 — draft-ready bell link uses the funding request id.
- O75 — no activity entry for applications converted before this change; backfill needs a decision.
- Pint: the touched API files already fail `pint --test` on older code (see O67); only the new test files were formatted.
- Not committed or pushed.
