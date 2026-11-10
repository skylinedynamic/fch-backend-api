# Minimum age 18 for every date of birth

**Date:** 2026-11-09
**Branch:** qa-round2-bugfix
**Author:** arafat-invento (with Claude)

## Problem
The platform needed one minimum-age rule (18) for every date of birth. The API had a different rule on each screen:
- embed form: 18+ (the only one with an age floor)
- funding application owner, co-borrower check, credit profile request: `date` only, which accepts "next tuesday", future dates, and rolls 2000-02-30 over into March
- borrower profile, profile change request, employees: `before:today` only
- admin/partner client create/update: no rule at all; a bad date was quietly saved as no date
- co-borrowers saved with the ownership step: DOB not checked

So a 10-year-old could be saved as a borrower, co-borrower or employee.

## Solution
A new rule, `App\Rules\DateOfBirth`, used for every DOB the API takes:
- must be a real calendar date written `YYYY-MM-DD`. The admin client form posts `MM-DD-YYYY`, so that request uses `DateOfBirth::acceptingUsFormat()`, which also accepts month-first dates. Day-first dates are rejected.
- not before 1900-01-01, not in the future
- at least `config('app.minimum_age')` (18) by the full date of birth: someone turning 18 today is allowed. The cutoff is today minus 18 years with no month overflow, so on 29 Feb the cutoff is 28 Feb, and someone born on 29 Feb turns 18 on 1 March in a common year.
- messages (`lang/en/common.php`): "Enter a valid date of birth.", "Date of birth cannot be in the future.", "You must be at least 18 years old to continue."
- empty values are left to `required` / `nullable`

The embed form now uses the same rule. Its `funding_application.embed_form.minimum_age` config and the `embed_form.minimum_age` message are removed. Its date of birth calendar offers every year up to today and opens an empty field on `latestDateOfBirth` (from `DateOfBirth::latestAllowedDate()`, so the calendar and the API always agree). An under-age pick shows the API's minimum-age message (`MINIMUM_AGE_MESSAGE`) on the field right away.

The two portals do the same (their handoffs `2026-11-09-date-of-birth-minimum-age-18.md`): every year up to today, an empty field opens 18 years back, a saved date opens on itself, and an under-age pick shows the message with a red border before save.

## Files changed
- `app/Rules/DateOfBirth.php`: new rule
- `config/app.php`: `minimum_age` => 18
- `lang/en/common.php`: three DOB messages; `lang/en/embed_form.php`: old message removed
- `config/funding_application.php`: embed-form-only `minimum_age` removed
- Requests now using `DateOfBirth`: Borrower `Profile/UpdatePersonalProfileRequest`, `Profile/StoreChangeRequest` (field `date_of_birth`), `Profile/StoreCreditProfileRequestRequest`, `FundApplication/StoreOwnershipInfoRequest` (owner, plus co-borrowers in `after()`), `MyApplication/CoBorrowerValidationRequest`; FCH Partner `FundApplication/StoreOwnershipInfoRequest` (same), `FundApplication/CoBorrowerValidationRequest`; FCH Common `Borrower/StoreBorrowerRequest`, `Borrower/UpdateBorrowerRequest` (US format), `Employee/StoreEmployeeRequest`, `Employee/UpdateEmployeeRequest`; Global `Website/EmbedForm/SubmitEmbedFormRequest` (applicant and co-borrowers). Messages for the removed `date` / `before` / `date_format` rules deleted.
- `app/Http/Controllers/Web/EmbedFormPageController.php`, `resources/views/embed-form/index.blade.php`: server-side calendar cutoff

## Tests
- Added: `tests/Unit/Rules/DateOfBirthTest.php` (25 tests): turning 18 today/tomorrow, future, invalid dates, before 1900, US format, 29 Feb in both directions, config-driven age
- Updated: `tests/Feature/Validation/IdentifierFormatRuleWiringTest.php`: every DOB field must use `DateOfBirth` (13 new cases)
- Added HTTP tests: `Feature/Profile/BorrowerPersonalInfoFormatsTest` (2), `Feature/FundApplication/BorrowerFundingApplicationStepsTest` (2: owner + co-borrowers, co-borrower check), `Feature/FundApplication/PartnerFundingApplicationStepsTest` (2: the same on the admin/partner wizard), `Feature/Borrower/BorrowerRecordsApiTest` (1: create/update, MM-DD-YYYY, non-existent date), `Feature/Employee/EmployeeApiTest` (1)
- Updated: `tests/Unit/EmbeddedForm/SubmitEmbedFormRequestTest.php` now checks the request's own rules instead of a copy of them
- Suites run: full `php artisan test`: 4404 passed, 2 skipped

## Open / follow-up
- `docs/test-findings.md` **D18**: partner/white-label registration has no DOB field; "today" is UTC; 29 Feb handling; existing under-age/bad rows are not migrated (saving them now fails until the DOB is fixed); employees included.
- Frontends: done (see their handoffs). Not checked in a real browser yet.
