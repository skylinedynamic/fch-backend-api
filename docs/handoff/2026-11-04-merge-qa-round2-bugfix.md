# Merge origin/qa-round2-bugfix into bugfix/round-2

**Date:** 2026-11-04
**Branch:** bugfix/round-2

## Problem
Merging `origin/qa-round2-bugfix` into `bugfix/round-2` left three files in conflict:
- `app/Models/FundingRequest/FundingRequest.php`
- `app/Services/FCH/Common/Application/RequestApplicationService.php`
- `lang/en/application.php`

One test from the incoming branch also failed once the two branches were combined.

## Solution
- **`FundingRequest::getMatchedLendersAttribute()`:**
  - **Kept:** this branch's one-line call to `LenderMatchService` (the matching moved there so both details pages score the same way).
  - **Moved into the service:** the incoming branch had edited the old inline copy. Both edits now live in `LenderMatchService::matchCategory()`:
    - **R2 BUG-020:** an inactive lender is not matched (`whereHas('lender', status = Lender::STATUS_ACTIVE)`).
    - **R2 BUG-008:** each match carries `display_name` ("Lender Name - Legal Name").
  - **Imports:** the `LenderVisibility` and `LenderProductCategory` imports the incoming side added are no longer used, so they were dropped.
- **`fundingApplicationStatusUpdate()`:**
  - **From the incoming branch:** the `DB::transaction` and `lockForUpdate` wrapper (R2 BUG-037: one status change at a time).
  - **From this branch:** the eager load of `with('borrower', 'fundingRequest')`.
- **`lang/en/application.php`:**
  - **`funding_application_locked`:** keeps this branch's wording. The merge base had the incoming wording, and this branch changed it on purpose to match `_staff`.
  - **Added from the incoming branch:** `status_changed_elsewhere` and `funding_application_changed`.
- **`RequestApplicationReviewTest::a_status_is_changed_without_a_note`:** the test from the incoming branch moved an application with no lender to Withdrawn. On this branch, every status outside the editable ones needs a lender (decision of 2026-11-03).
  - **Decided (user):** keep the rule and fix the test.
  - **Change:** the test now calls `assignLenderTo()` before Withdrawn. The rule itself is unchanged.

## Files changed
- `app/Models/FundingRequest/FundingRequest.php`: the conflict, resolved to the service call; the unused imports dropped.
- `app/Services/FCH/Common/Funding/LenderMatchService.php`: BUG-020 and BUG-008 from the incoming side.
- `app/Services/FCH/Common/Application/RequestApplicationService.php`: the transaction and the `fundingRequest` eager load.
- `lang/en/application.php`: the keys from both sides.
- `tests/Feature/Application/RequestApplicationReviewTest.php`: a lender is assigned before Withdrawn.
- `tests/Integration/Funding/LenderMatchServiceTest.php`: 2 new tests, inactive lender not matched and `display_name`. Both failed without the port.

## Testing
- **Related suites:** FundingRequest, Application, FundApplication, Unit/FundApplication and Funding. 464 passed and 1 skipped. The one failure was the Withdrawn test above; it passes now (`RequestApplicationReviewTest`: 20 passed).
- **`LenderMatchServiceTest`:** 12 passed. The 2 new tests failed with the port reverted.
- **Full suite:** see the session result.

## Open items
- **Not committed:** the merge itself is not committed yet.
