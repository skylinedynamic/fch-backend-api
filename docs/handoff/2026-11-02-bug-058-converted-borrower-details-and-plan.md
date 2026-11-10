# BUG-058: a converted submission's client details and real plan

2026-11-02

## Problem
After an embed-form submission was converted onto an existing client, the admin portal labelled it "Free Plan Borrower" and showed the submitted applicant's name. The API's `converted_borrower` sent only `id`, `full_name`, `status`, `is_free_trail` and `free_trail_end_date`. That was not enough to show the client's business, email, phone or real plan (e.g. "One Time Payment").

## Solution
`EmbedFormSubmissionResource::converted_borrower` now also sends:
- `email`
- `phone` (from `phone_number`)
- `business_name`
- `enrollment_type`, the enrollment type of the client's latest subscription (`null` for none)

The list (`getSubmissions`), the detail (`EmbedFormController`) and `updateStatus` eager-load `convertedBorrower.latestSubscription`, so the list doesn't run extra queries per row. Existing fields are unchanged. Converting never renames an existing client: `fillBlankProfileFields` fills only blank fields, and only with consent (BUG-064).

## Files changed
- `app/Http/Resources/FCH/EmbedForm/EmbedFormSubmissionResource.php`: the four new fields.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: eager-loads `convertedBorrower.latestSubscription` (list, `updateStatus`).
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`: same, for the detail.
- `tests/Feature/EmbedForm/EmbedFormSubmissionResourceTest.php`: 3 new tests.

## Testing
- New tests: red before the change ("Undefined array key"), green after.
- `tests/Feature/EmbedForm`: 164 passed.

## Open items
- Frontend side: `react-fch` handoff `2026-11-02-bug-058-converted-onto-existing-client-labels.md`.

## Follow-up (same day, after the first retest)
The Loan Applications list still showed "Free Plan". The list screen calls `EmbedFormController::fetchSubmissions`, which builds its own query and never loaded `convertedBorrower`. The first change had only touched `EmbedFormService::getSubmissions()`, which that screen doesn't use. `fetchSubmissions` now eager-loads `convertedBorrower.latestSubscription`. New test `EmbedFormSubmissionsApiTest::the_list_sends_the_client_a_submission_was_converted_onto_with_its_plan` failed before the change (no `converted_borrower` in the row) and passes after. `tests/Feature/EmbedForm`: 165 passed.
