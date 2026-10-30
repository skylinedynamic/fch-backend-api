# Agreement versions and server-built signed records

**Date:** 2026-10-30
**Branch:** agreement-in-config
**Author:** Awlad Hossain

## Problem
- **No record of past wording.** `agreement_templates` was overwritten on every save. Once a partner edited an agreement, nobody could tell which wording an earlier signer had agreed to, and a borrower who had the agreement open while it changed was recorded as agreeing to wording they never saw.
- **The signer's browser wrote the record.** The signed record (`agreement_signs.content_html`) was whatever HTML the signer's browser posted. Anyone calling `/agreement-sign/submit` directly could store altered terms as "their signed agreement". There was no hash and no user agent.
- **Stored XSS against the host team.** That browser-supplied HTML was rendered for the host team in an unsandboxed, same-origin iframe. Fixed in the portals; see their handoffs.

## Solution
- **Template versions** (`agreement_template_versions`).
  - `AgreementTemplate::currentVersion()` keeps the wording as a new version when `template_html`/`template_json` changed, or when the template predates versions. Saving the same wording or switching the template on/off adds no version.
  - Versions can never be updated or deleted (model guards); they go only with their template.
  - Saving a template records a version.
  - `check` returns `template.version_id`/`version`. The template resource returns `version`.
- **Signing a specific version.** `submit` accepts `agreement_template_version_id`.
  - If it is not the current version, the request gets **409** (`AgreementVersionOutdatedException`), and the portals reload the agreement.
  - Without it (a portal not updated yet), the current version is used.
  - Every new signature stores `agreement_template_version_id`.
- **Server-built record.** `submit` accepts `fields` (the values entered, by field key).
  - With `fields`, `SignedAgreementBuilder` builds the record from the version's HTML:
    - each field becomes its value as text (never markup), a checkbox ☑/☐, and a signature its image;
    - only plain raster `data:image/...;base64` is accepted for a signature (no SVG, URLs or `javascript:`);
    - scripts and the upload input are removed;
    - `.doc` height is lifted so every page shows in full, since no script paginates any more.
  - Fields are named by `data-field-id` (which the builder now writes), or `field_{n}` by order for older templates.
  - A required field left empty, or a bad signature, gets **422** with `errors.fields`.
  - Any `content_html` sent alongside is ignored.
  - The record's SHA-256 (`content_hash`) and `user_agent` are stored with the IP. `field_values` keeps the values, with the signature as an image reference rather than base64.
- **Legacy path (deploy transition).** Without `fields`, the old behaviour stays: the browser's `content_html` is stored, and `content_hash` is null. **Remove it once both portals are deployed.** Until then a direct API call can still use it.

## Files changed
- `database/migrations/2026_10_30_000001_create_agreement_template_versions_table.php`: versions table; `agreement_signs.agreement_template_version_id`.
- `database/migrations/2026_10_30_000002_add_signing_evidence_to_agreement_signs_table.php`: `content_hash`, `user_agent`.
- `app/Models/Common/AgreementTemplateVersion.php`: new, immutable, images cast.
- `app/Models/Common/AgreementTemplate.php`: `versions()`, `currentVersion()`.
- `app/Models/Common/AgreementSign.php`: new fillable columns.
- `app/Services/FCH/Common/ManageAgreement/SignedAgreementBuilder.php`: new.
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: versions on save/check/submit; server-built record.
- `app/Exceptions/AgreementVersionOutdatedException.php`, `app/Exceptions/AgreementFieldsInvalidException.php`: new.
- `app/Http/Controllers/Api/V1/{Borrower/Agreement,FCH/Common/ManageAgreement}/AgreementSignController.php`: validate `agreement_template_version_id`/`fields`; 409/422; pass the user agent.
- `app/Http/Resources/FCH/Common/ManageAgreement/AgreementTemplateResource.php`: `version`.
- `lang/en/agreement.php`: `version_outdated`, `fields_invalid`.

## Tests
- Added: `tests/Feature/Agreement/AgreementTemplateVersionTest.php` (9). Covers: versions per wording change; none for the same wording or a toggle; immutability; first version for older templates; `check` names the version; the signature records it; an outdated version gets 409; another agreement's version is refused; no version means the current one.
- Added: `tests/Unit/Agreement/SignedAgreementBuilderTest.php` (10). Covers: values as text; ☐/☑; typed markup escaped; signature image placed; bad signatures refused; scripts and upload removed; full pages; required fields; older templates by order; field listing.
- Added: `tests/Feature/Agreement/AgreementServerSignedRecordTest.php` (5). Covers: record built from the version and values with hash and user agent; browser HTML ignored; values kept without the image; required field gets 422; a partner signing the host's agreement.
- Checked by hand against the 6 local templates: every field found, no `<script>`/`<input>` left after building.
- Suites run: Agreement 64 passed. Full suite: 3186 passed, 20 skipped, 0 failed.

## Open / follow-up
- **Deploy:** run `php artisan migrate`. Deploy the API **before** both portals; they keep sending `content_html` too, so they also work against an API not yet updated.
- **Remove the legacy `content_html` path** once both portals are live.
- **Template scripts remain.** A partner admin can save a borrower agreement containing script, and it runs in their own borrowers' signing page. The builder's own pagination and calendar scripts live inside the template, so they cannot simply be stripped. The fix is the shared renderer planned in step 2 (agreement builder rework), after which `template_html` can be sanitised.
- **Viewing a server-built record:** it shows as one long page, not A4 pages (the PDF still paginates).
- **No stored PDF:** the PDF is still generated on download, now from the server-built record.
- **Signatures before this change** have no version and no hash; their `content_html` remains their only record.
