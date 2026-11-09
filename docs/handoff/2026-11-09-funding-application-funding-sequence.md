# Funding application Step 4: Funding Sequence dropdown, read-only Preferred Funding Type

**Date:** 2026-11-09
**Branch:** bugfix/embed-form-application-edit (all three repos)
**Author:** arafat-invento (with Claude Code)

## Problem
Editing a funding application (admin `intake-review/funding-application/{id}/edit`, and the borrower wizard), Step 4 "Funding Request":
- "Approved Funding Product" was a read-only box. For an application converted from the embed form (no funding request, no funding sequence) it stayed empty and could never be set.
- Preferred Funding Type could be changed. It must not change after the application has one.
- An old saved type name (e.g. "Line of Credit" on #114) showed as empty, because the wizard cleared any value that is not an Active funding type.

## Solution
- Label renamed to **Funding Sequence**.
- **Preferred Funding Type is read-only** once the application, or its funding request, has one. The API refuses a different value (422, `borrower_validation.preferred_funding_type_locked`), and the services put the stored value back in any case. An old name is kept as saved, not re-validated and not cleared.
- **Only for an application converted from the embed form** (an `embedded_form_submissions` row has `converted_funding_application_id` = the application; `FundingApplication::isFromEmbedForm()`, sent as `is_from_embed_form` on the step resource): Funding Sequence is a dropdown of the sequences offered for the Preferred Funding Type, loaded from new `GET /api/v1/common/funding-sequences?funding_type=…` (any signed-in user). It returns Active sequences of an Active type that are not in an Inactive group. An older name gets the sequences of the type it means through `FundingTypeName` ("Line of Credit" → "Business Line of Credit"). The save requires `funding_sequence_id` (`OfferedFundingSequence`), and `approved_funding` becomes that sequence's name, set by the server.
- **Every other application** (from a funding request, or created without one): read-only. The server keeps the stored `funding_sequence_id` / `approved_funding` whatever is sent, **on every step**. Before this, after the sequence was changed, a later step's save (Business Information sends `funding_sequence_id`) put back the first sequence id the admin wizard had loaded, because the wizard only set it once. The server now refuses that, and the admin wizard re-reads the id on each load.
- Picking a sequence updates the wizard's product name (page header) at once, not only after a save.
- One set of rules for both wizards: the `FundingRequestStepRules` FormRequest trait (validation) and the `FundingRequestStepFields` service (applied in both save services once the target application is known).

## Files changed
Backend
- `app/Models/Funding/FundingSequencing.php` — `scopeOfferedFor()`
- `app/Http/Controllers/Api/V1/Global/General/FundingSequenceOptionController.php`, `app/Services/Global/General/FundingSequenceOptionService.php`, `app/Http/Requests/Api/V1/Global/General/FetchFundingSequenceOptionsRequest.php`, `app/Http/Resources/Global/General/FundingSequenceOptionResource.php` — new endpoint (id + name only; the model's appended engine status is expensive)
- `routes/common.php` — `GET common/funding-sequences` under `auth:api`, outside the partner-portal group
- `app/Rules/OfferedFundingSequence.php`, `app/Http/Requests/Api/V1/Concerns/FundingRequestStepRules.php`, `app/Services/FCH/Common/Application/FundingRequestStepFields.php` — new
- `app/Http/Requests/Api/V1/{Borrower,FCH/Partner}/FundApplication/StoreFundingRequestRequest.php` — use the trait
- `app/Services/{Borrower/FundApplication/FundingApplicationService,FCH/Common/PartnerFundApplication/PartnerFundingApplicationService}.php` — apply `FundingRequestStepFields`
- `lang/en/funding_sequencing.php`, `lang/en/borrower_validation.php` — messages

Admin (`Fch-admin-frontend-react`) and borrower (`fch-borrower-react`)
- `src/.../steps/FundingRequestStep.tsx` — label, read-only type, dropdown vs read-only sequence
- `src/modules/funding-application/{services,queries}.ts`, `src/lib/query/queryKeys.ts` — `fetchFundingSequenceOptions` / `useFundingSequenceOptions`

## Tests
- Added: `tests/Feature/Funding/FundingSequenceOptionsTest.php` (list, filtering, older name, access, 422, 500)
- Added: `tests/Feature/FundApplication/FundingSequenceOnFundingRequestStepTest.php` (both wizards: embed app chooses/requires/validates/re-chooses the sequence, `is_from_embed_form`, type locked, old name kept, non-embed and funding-request apps keep their sequence)
- Added: `tests/Integration/Application/FundingRequestStepFieldsTest.php` (Defect: another step's save put back the old sequence), `tests/Support/ConvertsEmbedFormSubmissions.php` (test helper)
- Updated: `Partner/BorrowerEditsApplicationWithoutFundingRequestTest` — an embed app's save now sends a sequence and stores it, instead of staying null
- Updated: admin and borrower `FundingRequestStep.test.tsx` — the old preferred type is kept instead of cleared; new Funding Sequence block
- Suites run: backend full suite — only failures are the 2 `Unit/Rules/SafeUploadTest` cases, which fail locally because `enshrined/svg-sanitize` is missing from `vendor` (unrelated). Admin `tests/pages/applications/fundapplication` + `tests/modules`: 832 passed. Borrower `tests/pages/fundapplication`: 84 passed. `tsc --noEmit` clean in both frontends.

## Open / follow-up
- Old type names are still stored as they are. `php artisan funding-types:fix --dry-run` and then `funding-types:fix` (F184) rewrite them to the current category. This change doesn't depend on that.
- The new endpoint is gated by `auth:api` only, like `common/funding-types` is public reference data. It returns sequence names, nothing sensitive.
