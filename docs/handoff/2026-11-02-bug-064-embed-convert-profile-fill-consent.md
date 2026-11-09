# BUG-064: converting onto an existing client fills its profile only with the partner's consent

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-064 (R2-PP-EMB-07 / R2-OP-EMB-04, Embedded Form › Convert onto an existing borrower).
- Anyone can submit a public embed form with an existing client's email.
- Converting that submission onto the client quietly filled the client's **blank** profile fields from the anonymous submission: home address, DOB, full SSN, business address and phone, entity, industry, start date, revenue and FICO.
- Specific problems:
  - The dialog said only that a funding application would be added.
  - The client's Activity had no entry.
  - A partly filled address was mixed: State Texas (the client's) with City Miami (the submission's).
- The cause was `EmbedFormService::fillBlankProfileFields()`, called on every conversion onto an existing borrower.

## Solution
**Decision (product owner):** show the partner which fields would be filled, fill them only with the partner's consent, and record it in the Activity log.
- **Field list for the dialog.**
  - New `EmbedFormService::profileFieldsToFill()` returns `{field, label, value}` for each blank field the submission would fill. SSN and EIN show only the last four (`***-**-6789`).
  - `existingBorrowerSummary()` now takes the submission and adds the list as `fields_to_fill`. That covers both `GET …/conversion-preview` and the 409 `existing_borrower_confirmation_required` answer.
- **Consent.**
  - `ConvertEmbedFormSubmissionRequest` accepts `fill_profile_fields` (boolean).
  - When converting onto an existing borrower, the blanks are filled **only** when it is true. Otherwise only the funding application is added.
  - Fields that already have a value are never overwritten, as before.
- **Activity log.** When fields are filled, the client's Activity gets "Profile Filled From Application" (`EmbedFormService::PROFILE_FILLED_EVENT`). It names the fields filled, never their values.
- **Addresses filled whole.**
  - `blankProfileFields()` treats home address, city, state and postal code as one group, and the four business address fields as another.
  - If any field in a group is on file, nothing in that group is filled.
  - This also applies to `completeConvertedBorrowerProfile()` (the relocate command's backfill).

## Files changed
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`:
  - `fields_to_fill`, `profileFieldsToFill()`, `displayValue()`, `submissionProfile()`;
  - filling only on consent;
  - the Activity log entry;
  - `ADDRESS_GROUPS`.
- `app/Http/Requests/Api/V1/FCH/Common/EmbedForm/ConvertEmbedFormSubmissionRequest.php`: `fill_profile_fields`.

## Testing
- **`tests/Feature/EmbedForm/EmbedFormConversionProfileTest.php`.** 4 new tests, all failed first:
  - the list, with the SSN masked and not in the response, in both the preview and the 409;
  - without consent, the profile is untouched;
  - with consent, the blanks are filled and an Activity entry lists the field names, without the SSN;
  - a partly filled address is not mixed.
- **Updated:** 3 existing tests that expected blanks to be filled now send `fill_profile_fields: true`.
- **Run:** `tests/Feature/EmbedForm`, 147 passed (including `RelocateConvertedDocumentsCommandTest`).

## Open items
- **Old clients:** an older admin build that does not send `fill_profile_fields` never fills blanks. That is the safe default.
- **Not fixed (BUG-060):** the public form still reveals that an email belongs to another partner's client. The product owner deferred it.
