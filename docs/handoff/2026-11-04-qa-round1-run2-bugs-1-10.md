# QA Round 1 / Run 2 - bugs 1-10

**Date:** 2026-11-04
**Branch:** `bugfix/qa-round1-run2` (backend, admin frontend, borrower frontend - each from `origin/stage`, no upstream set)
**Author:** Arafat (with Claude Code)

## Problem
Ten Run 2 defects from the QA defect log (all Low / P3, assigned to Arafat):

| Bug | Portal · Module | What QA saw |
|---|---|---|
| BUG-153 | Owner · Funding Application list | Requested Amount sort ignored the amount shown for On Hold applications (from their funding request, BUG-136), so $40,000 sorted as the lowest. |
| BUG-156 | Client · application Activity | An "Everyone" staff note showed only as "Activity Created"; its Summary never appeared. |
| BUG-159 | Owner/Partner · Funding Request detail | After a rejection the page showed an empty required Reason box; the activity said only "Status updated to: Rejected". |
| BUG-161 | Partner/Host · Dashboard | "View all Applications" and the widget's "View All" opened Funding Requests. |
| BUG-162 | Partner/Host · Commissions | Search ignored Enter. |
| BUG-164 | Partner · Employees/Users | A host employee/user opened by id showed an empty form with Update. |
| BUG-165 | Partner · Add Employee | After a successful create the page stayed on the filled form. |
| BUG-167 | Client · Pre-Qualification | Leaving and coming back emptied the saved Funding Needs step. |
| BUG-168 | Client/Owner · Pre-Qualification submit | Simultaneous submits all accepted; request ended Failed under a COMPLETED review. |
| BUG-170 | Owner · Admin sidebar | A logo that failed to load showed a broken-image box. |

## Solution
- **153** - the list sorts `requested_amount` by `COALESCE(own amount, funding request's amount)`, the same fallback the column displays.
- **156** - the client panel titles a staff note (log type `activity`) by its Summary (`subject`, already in the API); the message stays under Show details.
- **159** - the API adds "Reason: ..." to the rejection's activity entry; the admin Review Progress card shows a Rejection Reason block and Review Actions opens with the saved reason. The reason was already stored and returned.
- **161** - both dashboard links go to `/intake-review/funding-application`.
- **162** - Enter in the Commissions search applies the filters.
- **164** - Employee/User Details show "Not Found" on the API's 404/403 (the API already refused; the pages ignored it), as Clients and Leads do since BUG-030.
- **165** - after creating an employee the page opens `/employee-details/{id}` (the list if no id returned). The seat-purchase flow is unchanged.
- **167** - **behaviour change (decision 2026-11-04, recommended option):** `GET /borrower/pre-qualification` no longer blanks Funding Needs; the On Hold draft resumes with what was saved. A new draft still starts blank (prefill), and submitted requests are never read here.
- **168** - submit claims the draft with one conditional UPDATE (`status = On Hold`), and retry likewise (`gpt_status = Failed`), so only one call starts a run; the losers get 404 as a second submit already did. `PreQualificationRequest::applyUnderwritingOutcome` now decides from the status as stored, so a run finishing on a stale copy still moves Failed back to Pending.
- **170** - new admin `BrandLogo` (ported from the borrower portal, BUG-141): the alt text is the site name; on load error, or with no URL, the site name is shown (its first letter in the collapsed sidebar).

## Files changed
Backend (`fch-backend-api`)
- `app/Services/FCH/Common/Application/RequestApplicationService.php` - Requested Amount sort (153)
- `app/Services/FCH/Common/FundingRequest/FundingRequestService.php` - reason in the rejection activity (159)
- `app/Services/Borrower/PreQualification/PreQualificationService.php` - Funding Needs on GET (167); atomic submit/retry claim (168)
- `app/Models/Borrower/PreQualificationRequest.php` - outcome from the stored status (168)
- `docs/test-findings.md` - F177-F186, O80

Admin (`Fch-admin-frontend-react`)
- `src/pages/applications/funding-request/FundingRequestDetail.tsx`, `components/ReviewProgressCard.tsx` (159)
- `src/pages/dashboard/Dashboard.jsx` (161)
- `src/pages/commissions/CommissionList.tsx` (162)
- `src/pages/employees/EmployeeDetails.jsx` (164, 165), `src/pages/users/UserDetails.jsx` (164)
- `src/components/Layout/BrandLogo.tsx` (new), `src/components/Layout/Sidebar.jsx` (170)

Borrower (`fch-borrower-react`)
- `src/pages/myapplication/components/detail/ApplicationActivity.tsx`, `src/modules/activity-log/types.ts` (156)
- `src/pages/prequalification/hooks/usePreQualificationForm.ts` - comment only (167)

## Tests
Each defect test was written first and seen to fail.
- Backend added: `Feature/Application/FundingApplicationListSortTest` (153), `Feature/ActivityLog/ActivityLogVisibilityTest::the_client_feed_carries_a_notes_summary` (156, contract), `Feature/FundingRequest/FundingRequestReviewApiTest::a_rejection_keeps_its_reason_on_the_detail_and_in_the_activity` (159), `Feature/PreQualification/BorrowerPreQualificationWizardApiTest::the_draft_resumes_with_its_saved_funding_needs` (167) and `::a_draft_another_submit_claimed_meanwhile_is_not_submitted_again` (168), `Integration/PreQualification/UnderwritingOutcomeStatusTest::a_completed_run_returns_the_stored_status_to_pending_though_its_copy_is_stale` (168)
- Admin added: `ReviewProgressCard.test.tsx` + `FundingRequestDetail.test.tsx` (159), `Dashboard.test.tsx` (161), `CommissionList.searchEnter.test.tsx` (162), `EmployeeUserDetails.notFound.test.tsx` (164), `EmployeeDetails.createRedirect.test.tsx` (165), `SidebarBrandLogo.test.tsx` (170)
- Borrower added: `ApplicationActivity.test.tsx` BUG-156 block (3 tests)
- Suites run: see "Results" below

## Results
_(filled in after the full runs)_

## Open / follow-up
- **O80** - "Everyone" client notes appear only inside an application's Activity panel; a client with no application never sees them (product decision).
- The BUG-168 race can't be run truly concurrently in-process. The Feature test simulates a second submit claiming the draft between the read and the write.
- The four touched PHP files already failed `pint --test` before this work (import order, indentation). They were not reformatted, to keep the diff to the fixes.
- Bugs 11-17 of the plan (174, 175, 176, 182, 184, 186, 199) are not started; 174 and 175 wait on the decisions in the plan.
- Nothing committed or pushed.
