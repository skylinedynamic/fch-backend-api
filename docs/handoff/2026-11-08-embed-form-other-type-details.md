# Embedded form: details for "Other" industry and entity type

2026-11-08

## Problem
The public embedded form (`/form/{key}`, Business step) offers "Other" for Industry Type and Entity Type, but it never asked what that "Other" is. The borrower portal's Profile does ask: it has "Entity Type Details" and "Industry Type Details", required for "Other".

So a converted client arrived with only "Other" on their profile and nothing more.

## Solution
**On the form:**
- Picking "Other" opens a required text field: "Industry Type Details" or "Entity Type Details", up to 255 characters.
- Each field sits beside its own select, one row per type. When only Entity Type is "Other", an empty spacer keeps Industry Type on its own row, so Entity Type and its details start the next row together. On a phone, everything stacks.
- Choosing a listed type again hides the field and clears it.
- The review step shows the details.

**On the server:**
- The details are required for "Other" (`required_if`), using the borrower profile's own messages ("Entity type details are required when entity type is Other.").
- The same rules serve the per-step check and the final submit.

**Stored:**
- New columns `embedded_form_submissions.industry_type_note` and `entity_type_note`.
- `EmbedFormSubmission` drops a note on save unless its type is "Other" (`TYPE_NOTES`; the "Other" value comes from `config/borrower.php`).

**Shown:** `EmbedFormSubmissionResource` returns both notes, and the admin panel's application details show them (react-fch).

**On conversion:**
- The notes go to the profile's `business_entity_type_note` and `business_industry_type_note`.
- For an existing client, a type and its details are filled together or not at all, like an address. The old `ADDRESS_GROUPS` constant is now `FILLED_TOGETHER`, so the client's own type is never paired with the submission's details.

## Files changed
- `database/migrations/2026_11_08_120000_add_type_notes_to_embedded_form_submissions_table.php`: the two columns (string, nullable).
- `app/Models/EmbedForm/EmbedFormSubmission.php`: `TYPE_NOTES` and a `saving` hook that keeps a note for "Other" only.
- `app/Http/Requests/Api/V1/Global/Website/EmbedForm/SubmitEmbedFormRequest.php`: the `required_if` rules and their messages.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`:
  - stores the notes
  - maps them to the borrower profile
  - `FILLED_TOGETHER` covers type + note
- `app/Http/Resources/FCH/EmbedForm/EmbedFormSubmissionResource.php`: `industry_type_note` and `entity_type_note`.
- `resources/views/embed-form/index.blade.php`: the two fields, the spacer, the `TYPE_NOTES` toggle and `placeTypeNotes()`, `CONDITIONAL_REQ`, and the review rows.

## Testing
- **New `tests/Feature/EmbedForm/EmbedFormOtherTypeNoteTest.php`** (10 tests):
  - the page has the hidden fields, conditionally required, with review rows
  - each type and its details share a row, with the spacer in place
  - "Other" requires details, with the profile's message
  - details are accepted when given, and not needed for a listed type
  - details are capped at 255 characters
  - details are kept for "Other" and dropped for a listed type
  - the resource returns them
- **`EmbedFormConversionProfileTest`**, two new tests:
  - details reach a new client's profile
  - an existing client's own type keeps its own details, and a blank type is filled with its details
- **Results:**
  - The new tests failed first and pass now.
  - `tests/Feature/EmbedForm`: 211 passed.
  - Pint was run on the new files only. The existing service and view were not reformatted whole, to avoid merge noise.
- **Browser check (Playwright, local form, 1280px and 390px):**
  - both "Other": each pair shares a row
  - only Entity Type "Other": Entity Type and its details share the second row
  - no "Other": Industry Type | Entity Type as before
  - phone: stacked
- **Local database:** migrated.

## Open items
- **Funding application:** it keeps `industry_type` / `entity_type` only. It has no note columns, so the details reach the client's profile but not the funding application.
- **Full suite:** not run for this change.
- **Not deployed:** not committed or pushed. Run `php artisan migrate` on deploy.
