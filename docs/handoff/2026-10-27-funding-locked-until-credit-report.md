# Funding access reported on profile completion

**Date:** 2026-10-27
**Branch:** feature/new-walk-through
**Author:** Awlad Hossain

## Problem
The borrower portal let a borrower open Funding before FCH had uploaded their credit report. Without the report, every funding criterion reads "Failed". The portal had no way to know whether the report was in.

## Solution
`ProfileCompletionService::checkCompletion()` now also returns:

```json
"funding": { "unlocked": true, "credit_profile_submitted": true }
```

- `unlocked`: `Borrower::hasVerifiedCreditScore()`, meaning `credit_profile_updated` is set and a `BorrowerCreditProfile` exists. The admin's `POST /common/borrowers/save-credit-profile` creates both. The borrower's own credit profile form sets only the flag, so it does not unlock Funding.
- `credit_profile_submitted`: the borrower has a `CreditProfileRequest`, or a report is already in. The portal uses it to say what is still to come.

The portal closes the Funding pages until `unlocked` is true (borrower-fch handoff of the same date).

## Files changed
- `app/Services/Borrower/Profile/ProfileCompletionService.php`: `funding` in the completion response (`fundingAccess()`)

## Tests
- Added: `tests/Integration/Profile/ProfileCompletionFundingAccessTest.php`:
  - locked without a report
  - locked when a report exists but the flag is not set
  - open with both
  - follows `hasVerifiedCreditScore()`
- Added: `tests/Feature/Profile/FundingAccessTest.php` (HTTP):
  - locked before anything is in
  - submitting the credit profile alone keeps it locked
  - opens after the admin's real save-credit-profile request
  - opens when the admin uploads a report without a borrower request
- Run: those plus the other Profile feature/integration tests and `BorrowerProfileApiTest`, 118/118 (the new ones watched failing first).

## Open / follow-up
- `GET /borrower/funding` and the funding detail endpoints do not check this themselves. If the funding data should be withheld, not just hidden in the portal, add the same check there. That needs a decision on what the walkthrough's results choice (which counts funding programs) shows meanwhile.
