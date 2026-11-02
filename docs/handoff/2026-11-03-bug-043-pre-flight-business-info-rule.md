# BUG-043: pre-flight "Business Information" uses the profile's rule

**Date:** 2026-11-03

## Problem
On Capital Access › Pre-Flight Checklist (`GET /borrower/capital-access/strategies/{id}/checklist`), the "Business Information" item said Missing without naming any field. At the same time, `/borrower/profile/completion` reported the business section complete. The two had separate field lists:

- **Checklist** (`HasEvaluatedChecklist`): required EIN and phone. It ignored country, zip code, and the details asked for when entity or industry type is "Other".
- **Profile** (`ProfileCompletionService`): the reverse. EIN has been optional since BUG-054, and phone is not required.

## Solution
- **One rule.** The checklist's Business Information item now takes `ProfileCompletionService::checkBusiness()`, the profile's rule, which is now public.
- **Missing fields.** The item also returns `missing_fields`, the same keys the profile completion sends, e.g. `["business_email"]`.
- **Other items.** They are unchanged and carry no `missing_fields`.
- **Effect on starting a play.** Starting a play (`startPlay`, `submitStep`) checks the same evaluated checklist. A borrower with no EIN or phone is no longer blocked by Business Information. "EIN Verified" is still its own item.

## Files changed
- `app/Services/Borrower/Profile/ProfileCompletionService.php`: `checkBusiness()` is public and documented as the shared rule.
- `app/Http/Resources/Borrower/CapitalAccess/HasEvaluatedChecklist.php`: the business item uses it and returns `missing_fields`.
- `tests/Feature/CreditBuilder/PreFlightBusinessInformationTest.php`: new.

## Testing
- **New:** `PreFlightBusinessInformationTest` (3):
  - the missing email is named;
  - the item is complete without EIN or phone;
  - zip code and the "Other" details are required.
- **Red first:** all three failed before the change.
- **Suites:**
  - `tests/Feature/CreditBuilder`, `tests/Feature/Profile` and `tests/Unit`: 1452 passed, 1 skipped.
  - `tests/Feature/CapitalAccess` and `tests/Integration/CreditBuilder`: 67 passed.

## Open items
- **Client portal:** shows the fields with a link to Profile › Business Details. See client-fch's handoff of the same date.
