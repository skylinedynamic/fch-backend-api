# Dead role permission assignment (F106, was O10)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F106 (was O10)

## Problem
`RoleController::assignPermissions()` and `RoleService::assignPermissions()` were dead code:
- **No route.** No route pointed at them, and neither portal calls them.
- **Could only throw.** The service filtered `permissions` by `partner_id`, a column that table does not have. Permissions are one shared list, gated by `is_host`/`is_certified`. Any call would have failed with an SQL error.
- **Would have wiped user permissions.** Even with the column, it deleted every direct permission of each user in the role. Any user-specific grants would have been lost.

Leaving it there invited someone to add a route on the assumption that it worked.

## Solution
- Removed both methods.
- Removed `AssignPermissionsRequest`, which was used only by the controller method.
- Removed the now-unused imports.

Roles get their permissions through role store/update, as before. The `users/{user}/permissions` route goes to `UserRoleController::assignPermissions()`, a different method, which is untouched. The `role_permission.permission_ids_*` lang keys are still used by the store and update requests, so they stay.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/RolePermission/RoleController.php`
- `app/Services/FCH/Common/RolePermission/RoleService.php`
- `app/Http/Requests/Api/V1/FCH/Common/RolePermission/AssignPermissionsRequest.php` (deleted)
- `tests/Feature/General/DeadRoutesTest.php`

## Tests
- `Feature/General/DeadRoutesTest::the_unreachable_role_permission_assignment_is_gone`, written to fail first and then passing. It checks that:
  - both methods and the request class are gone;
  - the routed `UserRoleController::assignPermissions()` is still there.
- `Feature/RolePermission` suite passes.
- Suites run: fch-api full suite — 2262 passed, 42 skipped, 0 failed. Real `storage/app` unchanged before and after.

## Open / follow-up
- Run `composer dump-autoload` after pulling. Otherwise the classmap still points at the deleted request file.
- The other dead-code findings (O19, O46, O53) are still open.
