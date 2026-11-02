# QA round 2 bug fixes (branch qa-round2-bugfix)

**Date:** 2026-11-01
**Branch:** `qa-round2-bugfix` in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react` (not committed, not pushed)
**Author:** arafat-invento

Source: QA Round 2 defect log (Google Sheet, gid 27742395). The 27 rows with **Assigned Dev = Arafat**, BUG-008 (assigned to Awlad in the sheet), BUG-011 and BUG-031 were done and then removed on request (skipped, F192, F195 and F204). Findings: `docs/test-findings.md` F192-F221, D14, O74-O77. Plan: `2026-10-28-qa-round2-bugfix-plan.md` (old numbering, see below).

## The sheet was renumbered

The plan of 28 Oct used the sheet's numbers of that day; the sheet has since been renumbered. Code comments and test docblocks now use the **current** numbers.

| Now | Was | Now | Was | Now | Was |
|---|---|---|---|---|---|
| 009 | 011 | 029 | 037 | 046 | 056 |
| 010 | 012 | 030 | 038 | 048 | 058 |
| 017 | 021 | 031 | 039 | 050 | 060 |
| 019 | 026 | 032 | 040 | 051 | 061 |
| 020 | 027 | 033 | 041 | 055 | 065 |
| 025 | 033 | 036 | 044 | 056 | 066 |
| 026 | 034 | 037 | 045 | 061 | 073 |
| 027 | 035 | 038 | 046 | 063 | 075 |
| | | 045 | 055 | 065 | 079 |

New since the plan: **BUG-073** (availability check) (BUG-008 and BUG-011 skipped).

## Problem and solution, per bug

| Bug | Module | Was | Now | Repos |
|---|---|---|---|---|
| 008 | Lenders | Duplicate lender name accepted | **Skipped 2026-11-02 on request** - fix removed, not changed | - |
| 009 | Funding Sequence | Min FICO 851 saved | 300-850 on all saves; input bounds | API, admin |
| 010 | Funding Sequence | State both allowed and restricted | `DisjointFrom` (states, industries); options exclude each other | API, admin |
| 011 | Roles | Admin role fully editable | **Skipped on request** - not changed | - |
| 017 | Profile | 9-digit phone / 6-digit ZIP | API refuses both (already on stage); field errors shown | API test, borrower test |
| 019 | Funding Request | Garbled "Lender Required" text | Plain sentence | admin |
| 020 | Funding Request | Inactive lender matched / assignable | Not matched; assign refuses it | API |
| 025 | Pre-qualification | Long name broke Review layout | Values wrap; grid shrinks | borrower CSS |
| 026 | Pre-qualification | Draft lost Funding Needs, restarted at step 1 | Draft answers returned; resumes at first unsaved step | API, borrower |
| 027 | Funding Application | Wrong id opened a blank wizard | 404 -> "Request not found" (read-only already on stage) | API, borrower |
| 029 | Employees | Status "[object Object]" | API takes 3 statuses; migration repairs rows; form sends values only | API, admin |
| 030 | Underwriting Logic | Inactive lender "Unknown Lender" | Own name + Inactive tag; in the filter | admin |
| 031 | Funding Application | Owner/co-borrower under 18 | **Skipped on request** - not changed | - |
| 032 | Funding Application | Note 256-500 chars -> 500 error | Column TEXT; maxlength 500 + counter (both wizards) | all 3 |
| 033 | Funding Application | Two tabs: stale save overwrote | `expected_version` -> 409; sticky Reload message (both wizards) | all 3 |
| 036 | Commission | Refused Success Fee silent | Error shown; card back to stored; 1-100 | admin |
| 037 | Update Status | Double-click applied twice | Click guard + row lock + repeat no-op + `expected_status` | API, admin |
| 038 | Close | No warning when funded >> requested | Warning + tick before Confirm | admin |
| 045 | Documents | Accepted in-use document deletable | 422 naming the request; reason shown; confirm says "accepted" | API, borrower |
| 046 | Appointment | Client could not cancel admin-booked | `POST appointments/{id}/cancel`; Cancel button; organiser emailed | API, borrower |
| 048 | Funding Request | Stale tab flipped Approved -> Rejected | `expected_status` -> 409, nothing sent; page reloads | API, admin |
| 050 | Partners | Cleared Address/Contact kept old value | Sent empty on edit | admin |
| 051 | Clients | Inactive employee in Assign to | Left out; current assignee kept, flagged | API, admin |
| 055 | Leads | Default country not in form data | Default put into the form | admin |
| 056 | PDF | Purpose note / timeframe / collateral missing | Rows added | API |
| 061 | Email | Partner emails carried host tagline | Partner's own slogan or none | API |
| 063 | Appointment email | No title/type/attendee/link | Summary, View Appointment, `.ics` | API |
| 065 | Clients | Two users: stale save reverted | `client_info_version` -> 409 | API, admin |
| 073 | Appointments | Live check sent record id (422) | Sends user id; clash shown before Confirm; shared clash names both | API, admin |

## Behaviour changes other apps will notice
- New optional request fields: `expected_status` (funding request / application status), `expected_version` (wizard steps, client info), `is_inactive` on each `common/get-all-users` member (the picker uses stage's `active_only` + `keep_ids`, which now also drops Inactive/Suspended employees). Without them nothing is checked.
- New **409** answers (stale save / decision) - no `errors` key on the wizard ones.
- New endpoint `POST api/v1/common/appointments/{id}/cancel` (borrowers allowed).
- Migrations (run on deploy): `2026_10_28_100000` (purpose_note, collateral_available -> TEXT), `2026_10_28_100100` (job_status repair).

## Files changed (main ones)
- **API:** `app/Rules/DisjointFrom.php`; `app/Exceptions/{StaleRecordException,DocumentInUseException}.php`; `FundingApplicationEditGuard`, `FundingRequestService`, `RequestApplicationService`, `BorrowerService`, `CommonService`, `DocumentService`, `AppointmentService`, `PreQualificationService`, borrower/partner `FundingApplicationService`; step resources + `Concerns/CarriesApplicationVersion`; Employee/Appointment requests; `AppointmentTimeRules` (clash wording); `MailableBranding`, `AppointmentNotification` + `emails/appointment/*`; `pdf/funding-application.blade.php`; `lang/en/*`; 2 migrations.
- **Admin:** `modules/appointment/availability.ts`, `modules/employee/logic.ts`, `modules/funding-application/{applicationRules,applicationVersion,staleApplication}.ts` + `services.ts`, `modules/funding/visibilityRules.ts`, `utils/staleRecord.ts`; `AppointmentModal.jsx`, `EmployeeDetails.jsx`, wizard steps, `FundingRequestDetail.tsx`, `FundingApplicationDetails.tsx`, `ConfirmationModal.jsx`, `FundedAmountModal.tsx`, `ClientInfoSection.tsx`, `LenderVisibilityGrid.tsx`, `LeadDetails.jsx`, `partner/logic.ts`.
- **Borrower:** `modules/funding-application/{applicationRules,applicationVersion}.ts`, `services.ts`, `saveFeedback.ts`; `modules/appointment/{cancel,services}.ts`; `prequalification/hooks/{resumeStep,usePreQualificationForm}.ts`; wizard steps + `CoBorrowerModal`, `FundApplication.tsx`; `Documents.tsx`; `AppointmentModal.tsx`; `assets/css/custom.min.css`.

## Tests
Every new test failed before its fix (checked against the old code where it mattered) and passes now. Each carries a `Defect: R2 BUG-0xx` docblock.

| Suite | Result (2026-11-01) |
|---|---|
After merging `origin/stage` (2026-11-01) and removing BUG-011:

| Suite | Result |
|---|---|
| API `php artisan test` (Unit + Integration + Feature) | 4335 passed, 2 skipped, 0 failed |
| Admin `vitest run` | 2403 passed, 0 failed (+2 `it.fails` KNOWN BUG markers from before R2) |
| Borrower `vitest run` | 1541 passed, 0 failed |
| Admin (`tsconfig.json`, `tsconfig.e2e.json`) + borrower `tsc --noEmit` | clean |
| Admin mocked e2e: all R2 specs + the get-all-users / task-assign specs touched by the merge | 70/70 passed (run with `E2E_BASE_URL=` against a fresh build) |

After removing BUG-008 and the Underwriting Logic follow-up (2026-11-02, F221):

| Suite | Result |
|---|---|
| API `php artisan test` | 4321 passed, 2 skipped, 0 failed |
| Admin `vitest run` | 2396 passed, 0 failed (+2 `it.fails`); 1 unhandled Bootstrap modal error from `FundingApplicationDetails.test.tsx` (seen before these changes too) |
| Admin `tsc --noEmit` (`tsconfig.json`, `tsconfig.e2e.json`) | clean |
| Admin mocked e2e `underwriting-logic-r2.spec.ts` | 7/7 passed. `funding-visibility.spec.ts` fails 13 on HEAD too, before these changes (O75) |

Admin e2e per bug: 009, 010, 030 `funding-sequence/underwriting-logic-r2`; 019, 048 `intake-review/funding-request/funding-request-details`; 029 `employees/employee-job-status`; 032, 033 `e2e/fund-application-r2`; 036, 037, 038 `intake-review/funding-application/funding-application-details-r2`; 050 `partners/partner-clear-fields`; 051, 065 `clients/client-r2`; 055 `leads/lead-default-country`; 073 `appointments/appointment-availability`. API-only fixes (020, 056, 061, 063) are covered by Feature/Integration tests - a mocked e2e would only test its own mock. Borrower-portal fixes (025, 026, 027, 032-033 borrower side, 045, 046) have vitest coverage; borrower e2e needs the live backend (O77).

Updated existing expectations: `AppointmentTimeAndClashTest` (2, new "You and X" wording), `BorrowerFundingApplicationStepsTest` (409 has no `errors`), `funding-request-details.spec.ts` (body carries `expected_status`), `LeadOwnerTeamTest` (BUG-051 on stage's `active_only` + `keep_ids`), admin `ClientAssignTo.test.tsx`. Stage's `ActivityEditModal.test.tsx` "replaces the saved attachments" failed on Node 22+ (undici refuses jsdom's File in `request.formData()`); its handler now parses the multipart body itself, same assertions.

Also (2026-11-01, D17): the admin Funding Application page no longer marks Status Notes required - the API never required it. Tests: `RequestApplicationReviewTest::a_status_is_changed_without_a_note`, admin `FundingApplicationDetails.test.tsx`.

## Open / follow-up
- **O74** last admin can still lose the Admin role (role assignment, and Edit Role since BUG-011 was skipped).
- **O75** ~83 older admin mocked e2e tests fail on HEAD as well (stale permissions etc.), not R2.
- **O76** version is to the second.
- **O77** borrower e2e not run (it uses the `.env` database).
- **D14** decisions taken as dev defaults - BA to confirm (Purpose Note 500 vs 2,000 in the QA case; Success Fee minimum 1%).
- QA retest needed on stage for BUG-025 (layout) and the email ones (061, 063) in Mailpit.
- Nothing committed; first push must be `git push -u origin qa-round2-bugfix` (never to `release`/`stage` directly).
