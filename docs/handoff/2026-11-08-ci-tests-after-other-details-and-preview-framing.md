# CI: two tests left behind by today's embed-form commits

**Date:** 2026-11-08

## Problem
CI ("Run Unit Tests") failed with 1 failed and 91 passed. Most of the log was dotenv's "Failed to open stream: .env" warning, repeated about 1,225 times, which hid the actual failure:

```
Tests\Unit\EmbeddedForm\SubmitEmbedFormRequestTest::every_business_field_is_required_but_the_ein_and_the_employees
industry_type_note should be required
Failed asserting that an array contains 'required'.
```

Two of today's commits changed behaviour without updating an older test. The code is correct in both cases.

1. **`d100b534`, "ask for details when industry or entity type is Other".**
   - It added `industry_type_note` and `entity_type_note` to the business step. They are required only when their type is "Other" (`required_if:…,Other`, `nullable`).
   - The older unit test expects every business field except the EIN and the employee count to carry a plain `required`.
2. **`fc88e5b5`, "let the partner's admin panel frame its form preview".**
   - `frame-ancestors` now also lists the host's admin panel (`app.frontend_partner_url`).
   - `SecurityHeadersTest::the_embedded_form_keeps_its_allowed_domains_as_its_framing_rule` still expected only the allowed domains.
   - CI had not reached this one yet, because the Unit step stops the run first. It would have failed on the Feature step next.
   - Locally it also depended on the `.env` (`BASE_PARTNER_PORTAL=localhost:5174`).

## Solution
Only the tests changed.
- **`SubmitEmbedFormRequestTest`:**
  - The two note fields are now checked as conditional: they carry `required_if:<type>,Other` and `nullable`, and not `required`. The test also asserts that both fields exist, so this branch cannot silently stop running.
  - New test `the_other_details_are_needed_for_other_and_only_then`. A note is required for "Other" (both types), not needed for a listed type, accepted when given, and refused over 255 characters.
- **`SecurityHeadersTest`:** pins `app.frontend_partner_url` and expects the admin panel origin after the allowed domains. It no longer depends on the machine's `.env`.

The end-to-end behaviour of the notes was already covered by `tests/Feature/EmbedForm/EmbedFormOtherTypeNoteTest.php`: 422 without details, details kept, the partner sees them. The preview framing is covered by `EmbedFormFramingTest`.

## Files changed
- `tests/Unit/EmbeddedForm/SubmitEmbedFormRequestTest.php`: conditional fields in the business-step check, plus one new test.
- `tests/Feature/General/SecurityHeadersTest.php`: pinned portal URL and the updated expected header.

## Testing
All three suites ran the way CI runs them, locally on PHP 8.3 with SQLite:

| Suite | Result |
|---|---|
| Unit | OK, 1318 tests, 1 skipped |
| Integration | OK, 542 tests |
| Feature | OK, 3230 tests, 1 skipped |

Before the change, Unit had 1 failure (the CI error) and Feature had 1 failure (`SecurityHeadersTest`).

## Open items
- **The `.env` warnings:** `ci.yml` has a `touch .env` step to silence them. The pasted run still showed them, so either that run predates the step or another workflow ran it. `deploy-stage.yml` and `deploy-release.yml` have their own copy of the step; worth checking that the failing job ran it.
- Branch `stage`. Not committed.
