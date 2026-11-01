# Embedded Form: delete Loan Applications that are not converted

**Date:** 2026-11-02
**Author:** awlad

## Problem
Embedded Form › Loan Applications had no way to delete an application, such as a test or spam submission.

## Solution
New `POST /api/v1/common/embed-forms/submissions/delete` with `{ ids: [...] }` (1 to 500 ids).
- **`EmbedFormService::deleteSubmissions()`:**
  - Deletes the rows. Attachments and co-borrowers go with them (FK cascade).
  - Then removes the uploaded files under `embed_forms/{partner}/{submission}/` on the public disk.
- **Converted applications are never deleted.** A converted one is the record behind a client and a funding application. If the selection includes one, the whole request is refused with 422 and nothing is deleted.
  - The message is `embed_form.converted_cannot_delete`.
  - `errors.converted` lists `{id, name}` for those applications, the same shape the credit account delete uses (`errors.applied`).
  - The new `EmbedFormSubmissionConvertedException` carries the list.
- **Scope:** a partner can delete only their own partner's applications. The host can delete any. Ids outside that scope are ignored, and if nothing is left the answer is 404.
- **No role permission on the route, for now (product owner's decision).** Only the partner scope above applies. A `delete application` permission was added and then taken out again, so nothing needs seeding.

## Files changed
- `routes/common.php`: the route.
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`: `deleteSubmissions()`.
- `app/Http/Requests/Api/V1/FCH/Common/EmbedForm/DeleteEmbedFormSubmissionsRequest.php`: new.
- `app/Exceptions/EmbedFormSubmissionConvertedException.php`: new.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `deleteSubmissions()`.
- `lang/en/embed_form.php`: `submissions_deleted`, `converted_cannot_delete`.

## Testing
- **New:** `tests/Feature/EmbedForm/EmbedFormDeleteSubmissionsTest.php`, 4 tests.
  - The rows, attachments, co-borrowers and files are deleted.
  - A converted application in the selection refuses the whole delete.
  - Another partner's application is not touched (404).
  - An empty selection gets 422.
  - Three failed first. The fourth, another partner's application, passed before the route existed (a missing route also answers 404); it became a real check once the route was added.
- **Run:** `tests/Feature/EmbedForm`, 151 passed.

## Open items
- Add a role permission when the product owner wants one.
- The host's **partner filter** on this list needed no API change: `submissions/fetch` already accepted `partner_id` for the host.
