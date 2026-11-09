# BUG-074 / BUG-075: credit profile needs a FICO Score, and saves/removals show in Activity

**Date:** 2026-11-03
**Branch:** bugfix/round-2
**Author:** Awlad Hossain

## Problem
Both on Owner › Clients › client › Credit Profile (QA round 2, client "QA-TEST R2 HLead Two" (168)).

- **BUG-074 (R2-OP-CRP-07).** A host user cleared FICO Score and clicked Save Credit Profile. `POST /common/borrowers/save-credit-profile` answered 200 "Credit profile saved successfully!" and stored the profile with no score. After that, Capital Access readiness read the profile as "FICO score is below the minimum", and the client got the "Your credit profile has been updated" email. The cause: the rule was `metrics.fico_score => ['nullable', new FicoScore()]`, and `FicoScore` skips empty values.
- **BUG-075 (R2-OP-CLI-21).** Saving the credit profile, or removing it (Delete Credit Profile › Yes, Delete All), left nothing in the client's Activity panel. Activity showed only the upload ("New document … was submitted"). Save never wrote an activity entry. Remove wrote only an application `Log::info`. It also deletes the report's document with a query-level `forceDelete()`, so the document model's own "removed" entry never fires either.

## Solution
- **BUG-074.** `metrics.fico_score` is now `required` (plus the existing 300–850 `FicoScore` rule), with the message `common.fico_score_required` ("Enter the FICO score."). A save without a score is a 422 on `metrics.fico_score`. Nothing is written and nobody is notified. A profile already on file keeps its score. The old test `a_report_without_a_fico_score_still_saves` was changed to match: every credit profile save now needs a FICO score (decision 2026-11-03).
- **BUG-075.** `ClientCreditProfileService` records two internal activity entries on the client:
  - `credit_profile_save`: "Credit Profile Saved", written after each successful save (called from `saveCreditProfile()` after the commit).
  - `credit_profile_delete`: "Credit Profile Data Removed", written at the end of `remove()`.

  Both entries go through the existing `ActivityLogService::logModelEvent()`. The causer is who made the change, and the timestamp is when. Visibility is **internal**, so only the host's team sees them: credit report data is FCRA material. The entries name no scores or report contents. Each is written in its own try/catch, so an activity failure is logged and never fails or undoes the save or the removal.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php`: FICO required plus its message. Calls `recordSaved()` after a save.
- `app/Services/Borrower/CreditProfile/ClientCreditProfileService.php`: `recordSaved()`, the activity entry in `remove()`, and private `recordActivity()`.
- `app/Models/ActivityLog/ActivityLog.php`: `LOG_TYPE_CREDIT_PROFILE_SAVE`, `LOG_TYPE_CREDIT_PROFILE_DELETE`.
- `lang/en/borrower.php`: `activity.*` subjects and descriptions, plus `logs.credit_profile_activity_failed`.
- `lang/en/common.php`: `fico_score_required`.
- Admin panel side: see the `Fch-admin-frontend-react` handoff with the same name.

## Testing
- New `tests/Feature/CreditProfile/CreditProfileActivityTest.php` (5 tests):
  - each save is an entry, with who saved it, and is internal
  - removal is an entry
  - a refused save or a remove with nothing to remove records nothing
  - an activity failure does not fail the save or the removal
  - the client does not see these entries
- `tests/Feature/Borrower/BorrowerCreditProfileApiTest.php`:
  - `a_report_without_a_fico_score_still_saves` was replaced by `a_fico_score_from_300_to_850_saves`.
  - New tests: `a_credit_profile_without_a_fico_score_is_refused_and_nothing_is_saved` ('' / null / missing / no metrics) and `clearing_the_fico_score_on_a_saved_profile_keeps_the_score_on_file`.
- `tests/Feature/CreditProfile/SaveCreditProfileNotifiesBorrowerTest.php`: a cleared FICO sends no email.
- `tests/Feature/CreditProfile/CreditProfileSsnMaskingTest.php`: one SSN save now carries a FICO score. Its SSN assertion is unchanged.
- The new tests failed first (200 instead of 422, missing constants), then passed after the fix.
- Full suite (`php artisan test`): 4573 passed, 2 skipped, 0 failed, after `composer install`. Before that, two `SafeUploadTest` SVG tests failed because `enshrined/svg-sanitize` was missing from the local `vendor/`. They were unrelated.

## Open items
- **Parser side note in BUG-074** (QA's synthetic report: FICO 712 read as 850, 0 trades). The FICO part is fixed in `2026-11-03-credit-report-upload-check-and-fico-parsing.md`. The 0-trades part still needs QA's PDF.
- The removal entry is the only trace that the report's document went. The document's own "removed" entry still does not fire, because the delete is query-level on purpose.
