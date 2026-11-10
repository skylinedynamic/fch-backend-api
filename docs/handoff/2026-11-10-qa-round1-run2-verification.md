# QA Round 1 Run 2: verification of Arafat's 23 bugs, stage merge, BUG-168 follow-up

**Date:** 2026-11-10
**Branch:** bugfix/qa-round1-run2 (fch-backend-api, Fch-admin-frontend-react, fch-borrower-react)
**Author:** arafat-invento

## Problem
1. `stage` was merged into `bugfix/qa-round1-run2` in all three repos, with
   conflicts (backend 5 files, admin 5, borrower 4). The borrower portal no
   longer built (`<<<<<<< HEAD` left in `ProfileCompleteCheckModal.tsx`).
2. The 23 Run 2 bugs assigned to Arafat needed checking against each one's
   *Expected* in the QA sheet, not just against their own tests.

## Solution
### Merge
- **Rule followed:** nothing stage added was removed. Where both sides made the
  same fix (Partner Not Found page, saved reject reason, `readMultipart` test
  helper, BUG-159 admin test), one copy was kept. Every other change from
  both sides is in the result, checked line by line against the merge base.
- `test-findings.md`: our F177-F197 / O80-O83 clashed with stage's IDs and were
  renumbered to F233-F253 / O81-O84 (stage's rows untouched; our two
  `qa-round1-run2` handoffs updated to match).
- Kept from our branch by decision: `status`/`assign_to` out of
  `UpdatePartnerRequest` (saved through `PUT partners/{id}/status-agent`), and
  no `permission:update partners` on partner archive/unarchive (decided
  2026-11-08).

### Verification against the sheet
All 23 match their Expected, with three changes made today:
- **BUG-194** (was New): invoices now say "Client Activation Fee"; see
  `2026-11-10-bug-194-client-activation-fee-invoice-name.md`, F254.
- **BUG-171**: Expected says the chosen status *saves on Update*; only the
  dropdown change was tested. Added tests proving the save (backend and
  admin). No code change was needed; the earlier admin failure was a
  slow-first-run timing problem.
- **BUG-168**: a repeat submit was blocked but answered 404 "not found"; QA
  expected "already submitted". Now 409 with that message (F255).

## Files changed (BUG-168 follow-up)
- `app/Exceptions/PreQualificationConflictException.php` (new, 409)
- `app/Services/Borrower/PreQualification/PreQualificationService.php`: `ownsRequest()`; 409 for the borrower's own submitted request / not-Failed retry / lost claim
- `app/Http/Controllers/Api/V1/Borrower/PreQualification/PreQualificationController.php`: catches it, answers via `ApiResponse::error(..., 409)`
- `lang/en/borrower.php`: `pre_qualification_already_submitted`
- `docs/test-findings.md`: F255

## Tests
- Updated: `tests/Feature/PreQualification/BorrowerPreQualificationWizardApiTest.php` (4 tests now expect 409 + message; another borrower's id still 404). Seen failing (404) before the fix.
- Added: `tests/Feature/Partner/PartnerStatusAgentApiTest.php::a_suspended_partner_can_be_reactivated` (Active, Inactive, OnBoarding)
- Added (admin): `tests/pages/partners/PartnerProfile.status.test.tsx`, "Update saves %s for a suspended partner"
- Backend full suite after merge + BUG-194: 5279 passed, 2 skipped. Pre-qualification tests after BUG-168: 78 passed.

## Open / follow-up
- Pre-existing on our branch before the merge (commit 7e5dc602), not caused by it:
  3 `tsc` errors in admin `tests/pages/employees/DepartmentModal.test.tsx`.
- From stage, left as is: duplicate IDs F231 and O74-O79 in `test-findings.md`;
  `RemoveUnclosedCommissions` cites "F177" for what is F176; README.md line 63
  has a leftover `>>>>>>> authentication` marker.
- O85: admin Subscription Details still labels the fee "Borrower Fee".
- QA sheet: BUG-194 can move to Fixed; BUG-168 and BUG-171 are ready to retest.
