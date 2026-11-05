# BUG-158: Ownership step no longer prefills the SSN's last four digits

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2
**Author:** awlad

## Problem
In the Client portal, any client with an SSN on file was stuck at step 2 (Ownership Information) of a funding application (`/fund-application/{id}`). The SSN field looked filled, showing as dots, but saving it unchanged returned 422 with "Enter a valid 9-digit SSN as XXX-XX-XXXX."

Cause: when the application has no SSN of its own yet, `OwnershipResource` falls back to the borrower's profile. It used `$borrower->last_4_ssn` for that fallback, which is a legacy accessor that returns only the last four digits of the encrypted SSN. The form prefilled those 4 digits, `SsnInput` showed them as a partly typed number, and the save sent them back. The `Ssn` rule has required nine digits since the SSN rules were unified, so the save was refused. Run 1 passed because the older rules accepted four digits.

The host/partner wizard (`FCH\Partner\FundApplication\OwnershipResource`) had the same fallback.

## Solution
- Added `HasEncryptedSsn::fullSsn()`. It returns the SSN on file only when all nine digits are there, and null otherwise (for older rows that hold only the last four, or when there is no SSN).
- Both `OwnershipResource`s now use `$borrower->fullSsn()` instead of `last_4_ssn`:
  - When the full SSN is on file, the step prefills with it, as an application that already has an SSN does. The field shows `***-**-1234` and the save keeps it.
  - When only the last four are on file, the field is left empty and the client types the full number.
- As decided, the full SSN still goes to the form the way it already did for an application with a saved SSN. The only change is that the last four digits are never sent as an SSN.
- No frontend change: an empty `ssn` already gives an empty field in both portals.

## Files changed
- `app/Models/Concerns/HasEncryptedSsn.php`: new `fullSsn()`.
- `app/Http/Resources/Borrower/FundApplication/OwnershipResource.php`: fallback changed to `fullSsn()`.
- `app/Http/Resources/FCH/Partner/FundApplication/OwnershipResource.php`: same change, in both the owner branch and the no-owner branch.

## Tests
- `BorrowerFundingApplicationStepsTest` and `PartnerFundingApplicationStepsTest` each get two new tests:
  - `the_ownership_step_prefills_the_full_ssn_on_file_not_its_last_four`
  - `a_profile_holding_only_the_last_four_prefills_no_ssn`
- Red first: all 4 returned `'6789'` before the fix.
- `tests/Feature/FundApplication`, `Borrower` and `Lead`: 609 tests passed, 1 skipped.

## Open / follow-up
- `last_4_ssn` is still used as an `ssn` value in `Borrower/Profile/BorrowerCreditProfileResource.php:24`, `FCH/Borrower/BorrowerCreditProfileResource.php:49` and `FCH/Borrower/BorrowerProfileResource.php:119`. These are display-only and not a save path, but they are the same kind of mix-up.
- `ProfileCompletionService:150` counts a profile with only the last four digits as having an SSN. It should check `hasFullSsn()`.
- The `last_4_ssn` accessor and mutator on `Borrower` are a compatibility shim left from the 2026-09-06 column rename. They should be removed in a separate change.
- The funding application and co-borrower APIs send the full SSN to the browser (masked only on screen). `funding_applications.ssn` and `funding_application_co_borrowers.ssn` are stored as plain text. Both were kept as they are for now by decision; consider a separate ticket.
