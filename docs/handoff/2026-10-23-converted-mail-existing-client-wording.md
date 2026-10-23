# Converted-to-client email: existing clients are not told a client was created

**Date:** 2026-10-23
**Branch:** `hotfix` (uncommitted)
**Author:** awlad

## Problem
Converting an embedded form submission onto a client who **already exists** creates only a new funding application, not a new client. The `EmbedFormConverted` emails still said one had been created:
- **Staff (host and partner):** subject "Applicant converted to a client — {name}", body "An embedded form applicant has been converted to a client."
- **Applicant:** "Your client account with {site} is now open."

## Solution
`EmbedFormConverted` takes an `existingClient` flag. `convertToBorrower()` passes `(bool) $existingBorrower` through `notifyConverted()` to every copy.

For an existing client, the emails now read:
- **Staff subject:** "New funding application for an existing client — {name}". The title matches.
- **Staff body:** "An embedded form application came from an existing client and has been added to their client record as a new funding application. No new client account was created. …"
- **Applicant body:** "Thank you for your new application. It has been added to your existing account with {site} …". The subject was already neutral ("Your funding application is in review").

The new-client wording is unchanged.

## Files changed
- `app/Mail/EmbedForm/EmbedFormConverted.php`: the `existingClient` parameter. It picks the staff subject and is passed to the view.
- `resources/views/emails/embed_form/converted.blade.php`: existing-client title and paragraphs. `$existingClient` defaults to false.
- `lang/en/embed_form.php`: `converted_existing_staff_mail_subject`.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `notifyConverted(..., bool $existingClient)`, called with `(bool) $existingBorrower`.
- `tests/Feature/EmbedForm/EmbedFormConvertExistingBorrowerTest.php`: 2 new tests (staff and applicant wording for an existing client).
- `tests/Feature/EmbedForm/EmbedFormConvertedMailSenderTest.php`: 1 new test that the new-client wording is unchanged.

## Tests
- The two existing-client tests failed before the change. The new-client test passed before and after.
- `tests/Feature/EmbedForm` + `tests/Integration/Mail` + `tests/Feature/MailConfiguration` + `tests/Unit/Notification`: **356 passed**.

## Open items
- The partner/host **bell** (`partner.embed_form.converted`) still reads "{name}'s embedded form application was converted to a client." for both cases. Changing it needs a second notification type or a template variable. Not done, because this request covered the email only.
- Not committed.
