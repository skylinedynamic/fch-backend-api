# Roles & Permissions: a static role cannot be edited (BUG-011, round 2)

**Date:** 2026-11-02
**Author:** awlad

## Problem
QA round 2 **BUG-011** (R2-OP-ROLE-02, Owner portal): the seeded **Admin** role could be fully edited (name, all permissions, Administrator Access, Update). QA did not press Update and asked whether the server protects it. It did not:
- `PUT /api/v1/common/roles/{role}` only blocked the Developer role, and only for non-developers.
- `is_static` (Admin and Developer in `RoleSeeder`) only blocked **delete**.
- `RoleService::update()` passes the new permissions to every user holding the role, so stripping Admin's permissions would lock out the only admin, with nobody left to restore them.

**Decision (product owner):** a role with `is_static = 1` is read-only. Its name and permissions cannot be changed.

## Solution
- **`RoleController::update()`:** a static role gets **403** "This is a system role. Its name and permissions cannot be changed." (`role_permission.role_static_cannot_update`). This runs after the partner check, so another partner's role is still a 404.
- **`RoleController::show()`:** the role detail now includes `is_static` (bool), so the portal can show the role read-only.
- Internal syncing is unchanged: `PartnerService` still updates the Admin role's permissions itself (for example on a plan change). Only the user-facing update is blocked.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/RolePermission/RoleController.php`: static check in `update`, `is_static` in `show`.
- `lang/en/role_permission.php`: `role_static_cannot_update`.
- `tests/Feature/RolePermission/RoleAndPermissionApiTest.php`: +2 tests.
  - `a_static_role_cannot_be_edited`: 403 with the message; the name, the role's permissions and the member's permissions are unchanged.
  - `a_role_says_whether_it_is_static`.

## Testing
- Both new tests failed first (the update succeeded; `is_static` was missing), then passed.
- `tests/Feature/RolePermission`: 21 passed (75 assertions).
- Full suite: FULL_SUITE_RESULT.

## Open items
- **Latent bug, not fixed here:** `RoleService::update()` does `throw ApiResponse::error(...)` for the Developer role. `ApiResponse::error()` returns a JSON response, not an exception, so it is a 500 ("Cannot throw objects that do not implement Throwable"). The seeded Developer role is static, so the new controller check now answers first. A non-static role with code `developer` would still hit the 500. The same `throw` is worth checking anywhere else it is used.
- The Owner portal (`react-fch`) shows a static role read-only; see its handoff of the same date.
- QA sheet BUG-011 → Fixed in build, pending stage retest.
