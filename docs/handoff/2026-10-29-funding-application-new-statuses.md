# Funding application: 7 new statuses and an explicit editable list (SCRUM-331)

## Problem
Seven funding application statuses were needed: Pending Clarification, Ready for Submission, Withdrawn, Offer Declined by Borrower, Closed - No Response, Closed - Incomplete Application, Closed - Error.

Editability used to be "order key 9 or below". An earlier commit on this branch reordered all statuses to fit the new ones into keys 1-9, which pushed Approved (Conditional) and Approved (Final) out of the editable range. That changed stage's behaviour by accident.

## Solution
- Stage's 19 statuses keep the order they have on stage (keys 1-19). The 7 new ones come after them (20-26). `STATUS` (dropdown and validation) and `STATUS_CLASS` use the same order.
- Editability is now an explicit list: `config/funding_application.php` → `statuses.editable`. It holds 11 statuses: the 9 that were editable on stage (On Hold, Draft, Submitted – Pending Review, Submitted (Received), Underwriting Review, Missing Documents, In Verification, Approved (Conditional), Approved (Final)), plus Pending Clarification and Ready for Submission.
- Every other status, including the other 5 new ones and any unknown status, is locked. Locked statuses let the borrower start a new funding request, as before.
- Pending Clarification and Ready for Submission are past signing, so an edit there costs the signature (back to Draft, re-sign), like Underwriting Review.
- `max_editable_key`, `FundingApplication::MAX_EDITABLE_STATUS_KEY`, `maxEditableStatusKey()` and the `max_editable_status_key` field in `editingState()` were removed. Neither frontend reads that field.

## Files changed
- `config/funding_application.php`: stage order plus the 7 new statuses; new `editable` list; `max_editable_key` removed.
- `app/Models/Borrower/FundingApplication.php`: `STATUS` / `STATUS_CLASS` in stage order plus the new 7; `editableStatuses()`, `lockedStatuses()` and `isEditable()` read the list.
- Comment fixes: `FundingRequestResource.php`, `RequestApplicationService.php`.

## Tests
- `tests/Unit/Services/FundingApplicationEditGuardTest.php`: exactly the 11 listed statuses are editable; the order is stage's 1-19 then the new 20-26; the locked list is updated.
- `tests/Feature/FundApplication/FundingApplicationEditLockTest.php`: Approved (Conditional) is editable again (edit costs a re-sign); Pending Clarification and Ready for Submission can be edited (re-sign); the 5 new closing statuses return 423.
- `tests/Feature/FundingRequest/ReapplyAfterSettledApplicationTest.php`: Underwriting Review is key 5 again.
- Unit suite and `tests/Feature/{FundApplication,FundingRequest,Application}` pass. `Tests\Unit\Assistant\NavigationMapTest` fails both with and without this change: it looks for a `/capital-access` route in `../fch-borrower-react/src/App.jsx`.

## Open
- See the follow-up section below.

## Follow-up: "Can't edit" message, status note limit, frontends
- The funding application details API (`/common/request-application/funding-application/{id}`, used by both portals) and the borrower My Application resource now return `locked_reason`: the `application.funding_application_locked` message for a locked status, `null` for an editable one. The edit-form API already returned `is_locked` / `locked_reason`.
- Admin portal (`Fch-admin-frontend-react`) and borrower portal (`fch-borrower-react`):
  - Status lists use stage's order plus the 7 new statuses (20-26), matching `statuses.order`.
  - Details page on a locked application: a disabled "Can't Edit Funding Application" / "Can't Edit Application" button plus a notice with `locked_reason`. Shown to the host, white-label partner, certified partner and borrower.
  - Edit form opened by URL on a locked application: a "Can't Edit Funding Application" card with the reason, not the form.
  - Admin Status Notes: `maxLength` 10,000, a character counter, and a check before submit. Matches `UpdateFundingApplicationStatusRequest` (`max:10000`, column is `text`).
- Tests: `tests/Feature/Application/FundingApplicationDetailLockedReasonTest.php` (all 4 viewers, locked and editable); frontend unit tests and e2e specs `e2e/locked-funding-application.spec.ts` (admin) and `e2e/sidebar/my-applications/locked-application.spec.ts` (borrower).
- Open: the borrower e2e suite needs the local backend on `127.0.0.1:8000`, which uses the `.env` database, so it was not run in this session.
