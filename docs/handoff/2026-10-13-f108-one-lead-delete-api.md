# One lead delete API (F108, was O19)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F108 (was O19)

## Problem
- **The bulk delete existed but could not be used.** `LeadController::multipleDelete()` / `LeadService::deleteMultiple()` had no route. They also deleted by id alone, with no partner scope and no activity log. If routed as they were, any partner could have deleted any other partner's leads without a trace.
- **The portal sent one request per lead.** The partner portal's "Delete Selected" called `DELETE /common/leads/{id}` once for every selected lead. If one call failed, some leads were already gone while the toast said "Failed to delete leads".
- **Two paths for one job.** Single and bulk delete went through different code.

## Solution
**One API for one lead or many: `POST /common/leads/multiple-delete`.**
- `DeleteLeadsRequest`: `ids` is required, holds 1–500 entries, and each must be a distinct integer.
- `LeadService::deleteMultiple($ids, $partnerId)` handles the delete:
  - it only touches leads of the caller's partner, and other ids are skipped;
  - each lead goes through `delete()`, so it is logged (`lead_delete`) exactly as before;
  - it all runs in one transaction.
- `LeadController::multipleDelete()` returns `{ deleted: n }`. It returns 404 when none of the ids is the caller's.
- `DELETE /common/leads/{id}` and `LeadController::destroy()` are removed. One lead is deleted with `ids: [id]`.

react-fch (partner portal) has its own handoff: `react-fch/docs/handoff/2026-10-13-one-lead-delete-api.md`.

## Files changed
- `routes/common.php`
- `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`
- `app/Services/FCH/Common/Lead/LeadService.php`
- `app/Http/Requests/Api/V1/FCH/Common/Lead/DeleteLeadsRequest.php` (new)

## Tests
New or changed tests, written to fail first and then passing:
- `Feature/Lead/LeadAndContactApiTest`:
  - several leads deleted in one call, each logged;
  - another partner's lead is never deleted (mixed ids, and theirs only gives 404);
  - validation of `ids`;
  - the single-lead open/edit/delete and the cross-partner tests now delete through the bulk endpoint.
- `Feature/General/DeadRoutesTest`: `DELETE /common/leads/{id}` is not registered, and the bulk route is.
- `Feature/Auth/BorrowerCommonRouteAccessTest`: borrowers are refused on the bulk route.

Suites run: fch-api full suite — 2275 passed, 42 skipped, 0 failed. Real `storage/app` unchanged before and after.

## Open / follow-up
- Deploy fch-api and react-fch together. An old portal build still calls `DELETE /common/leads/{id}`, which now returns 404 or 405.
- I checked every frontend under `c:\laragon\www`. Only react-fch calls the lead API.
