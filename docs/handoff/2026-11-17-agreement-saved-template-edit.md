# Agreement Builder: edit a saved template (API)

**Date:** 2026-11-17
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain

## Problem
Saved agreement templates (`2026-11-17-agreement-save-as-template.md`) could only be created, used and deleted. Changing one meant saving a copy under a new name and deleting the old one.

## Solution
New `PUT /api/v1/common/agreement-templates/saved/{id}` takes `name`, `template_json`, or both (at least one, `required_without`).
- **Rename:** the name is trimmed, max `NAME_MAX`, and unique per owner and type, case-insensitive. A template may keep its own name in another case. Another template's name is refused (422 on `name`).
- **Replace:** `template_json` must be JSON. Images go to disk through the existing cast.
- The type (`slug`) never changes.
- **Owner only:** another partner's template gives 404. The on-behalf rule (`partner_id`, host plus `manage partner configuration`) is the same as for the other endpoints.
- The duplicate-name check moved into a private `ensureNameFree()` shared by create and update.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Common/ManageAgreement/AgreementSavedTemplateUpdateRequest.php`: new, the update rules.
- `app/Services/FCH/Common/ManageAgreement/AgreementSavedTemplateService.php`: `update()`, `ensureNameFree()`.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementSavedTemplateController.php`: `update()`.
- `routes/common.php`: `PUT saved/{id}`.
- `lang/en/agreement.php`: `saved_template.updated`.

## Tests
- `tests/Feature/Agreement/AgreementSavedTemplateTest.php`: +7 (rename keeps the agreement; replace keeps the name; both at once, slug unchanged; own name allowed, another refused; validation; another partner's gives 404; on-behalf 403 then 200), plus the guest check on PUT. All failed first.
- Suites run: `tests/Feature/Agreement`, 115 passed.

## Open / follow-up
- No migration needed beyond `2026_11_17_000001_create_agreement_saved_templates_table`.
