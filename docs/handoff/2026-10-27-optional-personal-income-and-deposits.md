# Borrower profile Financial tab: optional income and deposits, required revenue

**Date:** 2026-10-27
**Branch:** feature/new-walk-through
**Author:** Awlad Hossain

## Problem
- The borrower's Financial tab could not be saved without `annual_personal_income` and `average_monthly_deposits`, both `required` in `UpdateFinancialInfoRequest`. `ProfileCompletionService::checkFinancial()` also counted them as missing, which kept the profile incomplete and held the Getting Started walkthrough on `complete_profile`.
- Monthly and Annual Revenue must stay required: null cannot be saved, but 0 can. The API already enforced this. The portal sent blank revenue as `0`, which is fixed in the portal's handoff of the same date.

## Solution
- `UpdateFinancialInfoRequest`: income and deposits are now `nullable|numeric|min:0|max:999999999.99`. A given value is still checked. The unused `annual_personal_income.required` message was removed.
- `ProfileCompletionService::checkFinancial()`: only monthly and annual revenue are required. `requireField()` already counts 0 as filled in.
- Revenue rules are unchanged (`required|numeric|min:0`); they are now pinned by tests.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/Profile/UpdateFinancialInfoRequest.php`: income and deposits nullable
- `app/Services/Borrower/Profile/ProfileCompletionService.php`: dropped from the completion check

## Tests
- Added: `tests/Unit/Profile/FinancialInfoOptionalFieldsRulesTest.php`:
  - income and deposits accept absent or null values, and still reject negative or non-numeric ones
  - revenue refuses null, `''` and blanks, and accepts `0`, `'0'` and `'0.00'`
- Added: `tests/Integration/Profile/ProfileCompletionOptionalFinancialFieldsTest.php`: Financial is complete without income and deposits; revenue of 0 counts as filled in; empty revenue is missing
- Added: `tests/Feature/Profile/OptionalFinancialFieldsTest.php` (HTTP):
  - saves without income and deposits, or with them null
  - a given amount is saved and validated
  - null revenue gets 422 with "Monthly/Annual revenue is required"
  - revenue of 0 is saved and completes the section
  - `/profile/completion` does not ask for income or deposits
- Run: the three files 19/19 (the income/deposit tests were watched failing first; the revenue tests describe existing behaviour). With `BorrowerProfileApiTest` and `OnboardingStepTest`, all passed. Full suite (before the revenue tests were added): 3095 passed, 2 skipped, 1 failed. The failure is the unrelated NavigationMapTest `/capital-access` route (O63 in `docs/test-findings.md`).

## Open / follow-up
- `StoreChangeRequest` still requires `annual_personal_income`, on purpose: a change request needs a new value.
- `ReadinessScoreCalculationService` treats a missing personal income as 0, and `LenderVisibility` treats missing deposits as not meeting a lender minimum. Borrowers who skip these fields will score and match lower.
