# BUG-181: a resource needs the link or document its type uses

**Date:** 2026-11-06

## Problem
Host admin → Resources → Manage Resources → + Add Resource. A Training Academy resource with a title and no Resource Link was saved ("Resource created successfully"). It went live for partners and clients as an empty "Visit Link" item.

Saving an empty form reported only "The Resource Title is required.", even though the form marks Resource Link / Resource Document as required (*).

`StoreResourceRequest` and `UpdateResourceRequest` had `link` and `file` as `nullable` for every type. The `link_required` / `file_required` messages already existed in `lang/en/resource.php` but were never used.

## Solution
- `Resource::TYPES_REQUIRING_LINK = [training_academy]` and `Resource::TYPES_REQUIRING_FILE = [legal_registration, download_assets, education_help]`.
  - Business Services is in neither list. The form shows no resource link for it; it lists its providers' links instead.
- **Store:** `link` / `file` use `Rule::requiredIf(type in the list)`. Laravel reports every failing field at once, e.g. title and link together.
- **Update:** the same rules, using the type from the request or the saved type.
  - A field is required when the request sends it, so it cannot be cleared.
  - It is also required when the saved resource has none, for example after switching the type to one that needs it.
  - An edit that leaves the field out keeps what is saved.
- The admin panel now opens a new resource as Unpublished: `Fch-admin-frontend-react/docs/handoff/2026-11-06-bug-181-resource-form-unpublished-default.md`.

## Files changed
- `app/Models/Resource/Resource.php`: the two type lists.
- `app/Http/Requests/Api/V1/FCH/Admin/Resource/StoreResourceRequest.php`: required-if rules and messages, plus `typeNeeds()`.
- `app/Http/Requests/Api/V1/FCH/Admin/Resource/UpdateResourceRequest.php`: required-if rules and messages, plus `mustKeep()`.
- `tests/Feature/Resource/ResourceAdminApiTest.php`: 2 new tests.

## Testing
- `ResourceAdminApiTest`, red first: 2 new tests failed (no `link` error; 200 instead of 422). Green after the change.
- `tests/Feature/Resource` + `EmbedFormSubmissionResourceTest`: 20 passed.
- Admin live E2E against this API (`e2e/sidebar/resources/resource-form-validation.live.spec.ts`): passed. An empty save gives 422 with title and link errors, a title alone gives 422 with only `link`, and nothing is created.

## Open items
- Existing resources of these types without a link or file can no longer be saved from the edit form until one is added. Local DB has none; staging and production were not checked.
- Business Services providers' links stay optional.
- Not committed.
