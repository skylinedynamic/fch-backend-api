# Fixed lender made Inactive is still shown (SCRUM-434 S434-BUG-05)

**Date:** 2026-11-09
**Branch:** bugfix/embed-form-application-edit
**Author:** arafat-invento (with Claude)

## Problem
Owner portal › Funding Application FUND-0009090 (app 90, Closed (Completed)) and its Funding Request 47: Lender Information showed only the lock notice and an empty table, "The assigned lender is not among the lenders matched for this application." The assigned lender, R2 Lender A, had been made Inactive after it was assigned. The API still returned `assigned_lender_ids [24]` and `can_assign_lender false`, so the lender was there but hidden. Closed applications with Active lenders (88, 89, 93, 106, 110) were fine. Retest 09-Nov: not fixed.

Cause: the S434-BUG-02 fix made `LenderMatchService` skip Inactive lenders (so one cannot be assigned), and the locked view shows the assigned lender only from `matched_lenders`.

## Solution
- `LenderMatchService::match($borrower, $categories, $alwaysShowLenderIds = [])`: lenders in the last list are matched whatever their status. One no longer listed in the application's funding types is scored against a category it is still listed in. The per-lender scoring moved into `score()`, unchanged. Every row now has `is_active`.
- `FundingApplicationResource` and `FundingRequest::getMatchedLendersAttribute()` pass the assigned lender only when it is fixed (`canAssignLender()` false, i.e. past the 11 editable statuses with a lender assigned). While the lender can still be chosen, an Inactive lender is still not offered (BUG-02 unchanged).
- Admin `LenderInformationCard`: the fixed-lender card shows an "Inactive" badge when `is_active` is false; `MatchedLender.is_active` added to the type.

Preferred / Additional Funding Types: both pages already match on the application's Preferred + Additional Funding Types (request 2026-11-05); the embed form and both wizards offer them from the lender product categories and validate them with `KnownFundingType`. No change needed there.

## Files changed
- `app/Services/FCH/Common/Funding/LenderMatchService.php`
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`
- `app/Models/FundingRequest/FundingRequest.php`
- admin `src/components/Common/Lender/LenderInformationCard.tsx`, `src/modules/application/types.ts`

## Tests
- Added: `tests/Feature/Application/FundingApplicationLenderAssignmentTest.php`: `a_fixed_lender_made_inactive_is_still_shown_on_both_pages` (6 locked statuses, application + funding request pages, `is_active` false) and `an_inactive_lender_is_not_offered_while_the_lender_can_still_be_chosen` (guard). Failed first: the lender was missing.
- Added: admin `tests/components/Common/Lender/LenderInformationCard.test.tsx`: Inactive badge on the fixed card; none for an Active lender.
- Run: lender assignment suite 60 passed; admin Lender + funding detail pages 156 passed, `tsc` clean.

## Open / follow-up
- An assigned lender that was **deleted** (soft-deleted) is still not shown; only Inactive is covered.
