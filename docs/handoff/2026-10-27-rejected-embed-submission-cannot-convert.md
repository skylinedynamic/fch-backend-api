# A rejected embed-form submission cannot be converted (BUG-098)

Date: 2026-10-27

## Problem
Loan Applications › a submission with Submission Status = Rejected › Convert to Free Plan Borrower. The conversion went through and the status became Converted. `EmbedFormController::convertibleSubmission()` only refused submissions that were already converted.

## Solution
`convertibleSubmission()` also refuses a rejected submission, with a 422 and `embed_form.rejected_cannot_convert`. That covers both the conversion preview and the conversion. To convert, the status is first moved back to New or In Review, so a rejection is never undone by one click. The admin screens disable the button with the same explanation.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`: rejected check.
- `lang/en/embed_form.php`: `rejected_cannot_convert`.
- `tests/Feature/EmbedForm/EmbedFormSubmissionsApiTest.php`: a rejected submission can be neither previewed nor converted; nothing is created and the status stays Rejected.

## Testing
- The new test failed first (200 instead of 422), then passed. `tests/Feature/EmbedForm`: 142/142.

## Open items
- None.
