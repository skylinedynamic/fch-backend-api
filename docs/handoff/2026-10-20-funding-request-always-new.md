# Funding request always starts new

Branch: `feature/funding-request-always-new` (from `release`) in `fch-backend-api` and `fch-borrower-react`. `Fch-admin-frontend-react` has the same branch with no changes.

## Problem
- `/funding/:id` in the borrower portal showed "Edit Application" (e.g. "Your funding application is On Hold. You can still review and edit it."), "View Application", "Process Application", or a disabled "Start Funding Request" ("Already applied") whenever the borrower already had a request or application for that option.
- The `/funding/:id/review` wizard redirected on a pending or approved earlier request and prefilled the last request's amount and use of funds.
- `POST /api/v1/borrower/funding/requests` refused while a request was Pending ("Already applied...") or an application was still in review ("You already have a funding application in progress..."). After a rejection it overwrote the rejected request in place, so the resubmission kept its old id and did not show first in the admin Funding Requests list.
- Uploading/removing a document in the wizard wrote the document type into an older request's `documents_uploaded`.
- "Submit for Underwriting" sent the request without confirmation.

## Solution
Product decision: every funding request is a new one, and existing requests/applications are never changed by it.
- API `App\Services\Borrower\Funding\FundingRequestService`:
  - `store()` - removed the pending and in-progress checks and the reuse of the latest rejected/in-progress/accepted request; always `FundingRequest::create()`. Notifications and the activity log fire for every new request.
  - `uploadDocument()` / `deleteDocument()` - no longer update any existing request's `documents_uploaded` (the new request sends its own on submit).
  - Removed the unused `funding_request.application_already_in_progress` message.
- Borrower portal:
  - `src/pages/funding/components/FundingDetail.tsx` - footer always "Start Funding Request" -> `/funding/:id/review`; disabled only for a Hidden option or missing underwriting factors.
  - `src/pages/funding/FundingRequestWizard.tsx` - no pending/approved redirect, no prefill from `latest_requested_amount` / `latest_use_of_funds`; "Submit for Underwriting" opens the shared `ConfirmationModal` (flip animation), and the POST runs only on "Yes, Submit".
- Admin lists were already newest first (requests `id desc`, applications last-updated first); with a new row per submission, a new request shows at the top.

## Tests
- `tests/Feature/FundingRequest/ReapplyAfterSettledApplicationTest.php` - the "in review blocks a second request" test now expects a new request; added: On Hold does not block, Pending does not block, a rejected request is kept and a new one created, a new request is listed first for the admin. Existing applications are asserted unchanged.
- `tests/Feature/FundingRequest/BorrowerFundingRequestApiTest.php` - upload/remove a document does not change an existing request.
- Borrower `tests/pages/funding/FundingDetail.test.tsx` - rewritten for the new rule (always Start Funding Request; disabled only when not available).
- Borrower `tests/pages/funding/FundingRequestWizard.test.tsx` (new) - empty form, no redirect on approved/pending, confirmation before submit, cancel sends nothing.

## Follow-up: approved banner did not show
- Cause 1 (API): `DashboardService::activeApprovedFundingRequests()` treated a request as started when *any* application existed on the same funding option - including one on an older request (e.g. On Hold). With every request now new, a newly approved request was hidden and the banner never showed. Now: started = an application on this request; the same-option fallback only applies to an application linked to no request.
- Cause 2 (portal): `GET /borrower/active-funding-requests` (banner + sidebar badge) is cached 5 minutes and nothing called it again on approval, so it only ran after the cache expired or a hard reload. `src/modules/notifications/useNotificationStream.ts` now invalidates `queryKeys.dashboard.activeFundingRequests()` (that endpoint only, not the whole dashboard) when the live Reverb push `borrower.funding_request.status_changed` arrives - sent by host and partner alike, for every status (a rejection just returns nothing new) - and after a socket reconnect. Other pushes do not call it; creating the application already refreshes it through the step save.
- Submit confirmation modal uses a question icon (`ri-question-line`, warning colours) instead of the celebration animation.
- Tests: `tests/Feature/Borrower/BorrowerActiveFundingRequestsTest.php` (an application on an older request does not hide a newly approved one); borrower `tests/modules/notifications/useNotificationStream.test.tsx` and `tests/components/Layout/LayoutRealtimeBanner.test.tsx` (new: push -> endpoint called -> banner shows, no reload); wizard test checks the icon.

## Left open
- The `/borrower/funding/:id` payload still returns `latest_review_*`, `has_funding_application`, `can_start_new_funding_request` and the `funding_application_*` fields; the card no longer uses them.
- The "Update Application" link on a rejected request (funding-requests list/detail/modal) still passes that request's amount and purpose into the wizard; submitting it now creates a new request instead of overwriting the rejected one.
- Step 3 (Documents) still lists the borrower's existing documents of each required type - they are the borrower's document library, not data from an earlier request.
- Several requests can now be in progress for the same borrower and option at once (the old guard against parallel pipelines is gone by decision).
