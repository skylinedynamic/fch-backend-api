# Reserved Developer role and the developer-only Business Insights permissions

**Date:** 2026-11-18
**Branch:** feature/owner-summary
**Author:** Awlad Hossain

## Problem
- Any role could be created or renamed as "Developer" (any case), so another role could pass for the reserved one, and a create took the `developer` code from its name.
- A non-Developer could give someone the Developer role. `POST users/{user}/roles`, user create/update and employee create/update all accepted the Developer role's id.
- Developer accounts showed in lists outside the Users screen: employees (list, page, show), the team pickers (`get-all-users`, `borrowers-get-all-users`), the appointment people list, and as members of any second role they held.
- Business Insights (Owner Summary) had no permission. The admin panel limited it to one hard-coded email. The API let in any "host owner".

## Solution
- **One Developer role, the host's:**
  - `RoleSeeder` creates Developer for the host partner only; no other partner gets one.
  - `Role::isDeveloper()`, the `Role::scopeTheDeveloperRole()` scope and `User::isDeveloper()` count only the host's `developer` role. A stray `developer`-coded role under a partner (from an old seed) makes no one a Developer and never shows the Business Insights permissions.
  - `BusinessInsightsPermissionSeeder` gives the permissions to that one role only.
  - On local `fch_dev` there is exactly one: role #1, Four Corner Holdings, with 1 user.
- **Who may change a role:** `Role::isEditableBy($user)`, used in `RoleController::update` (403 otherwise):
  - A Developer may change every role's permissions, the static Admin included. A static role's name stays fixed; only permissions and description change.
  - **The Developer role is fixed for everyone, a Developer included.** A Developer user's direct permissions can't be changed either (`users/{user}/permissions` returns 403), because they are the Developer role's.
  - Anyone else may change non-static roles only, as before.
  - Each role in the list and detail responses now carries `is_editable` for the viewer.
- **Reserved role** (`Role::CODE_DEVELOPER` / `NAME_DEVELOPER`, `Role::isReservedName()`):
  - The new `NotReservedRoleName` rule on role store/update returns a 422 on `errors.name`: `role_permission.name_reserved`. The check ignores case and padding; a name like "Developer Relations" is still allowed.
- **Who may give the role:**
  - The `Role::assignableBy($user)` scope covers the partner's own roles, and the Developer role only when `$user` is a Developer.
  - The new `AssignableRole` rule applies it on user/employee store/update (`role_id`), and it is also used in `UserRoleController::assignRoles` and both services' `assignRoleAndPermissions`.
- **Hidden accounts:**
  - `User::scopeHideDevelopersFrom($viewer)` and `Employee::scopeHideDevelopersFrom($viewer)` hide Developer accounts unless the viewer is a Developer.
  - Applied to the user list, user page, employee list/page/show, team pickers, appointment selectable users and role members.
  - `User::isDeveloper()` replaces the `hasRole('Developer')` string checks in the files this change touched.
- **Developer-only permissions:**
  - New `permissions.is_developer` column.
  - `PermissionInfo(developer: true)` and `Permission::isDeveloper()`; the config adds `is_developer: 1` only where it is set.
  - New module `PermissionModule::BusinessInsights` with: `view business insights`, `export business insights`, `manage business insights report`.
  - `PermissionService::assignableQuery()` hides these permissions from the picker for non-Developers.
  - The picker (`POST common/permissions`, flat or grouped) lists the Business Insights group **first**: ordered by `is_developer` desc, then id. The admin role form keeps the API's order.
  - **A Developer may give them to the host's roles and users only, never to another partner.** `grantableQuery($user, $toHost)` drops them unless the receiving role or user is the host's, and only a Developer has them in its assignable set.
  - Any host user holding them sees the matching UI. The API checks each permission.
  - `keepingDeveloperOnly()`: a host admin, who cannot see these permissions, does not strip them when it saves a role or a user's direct permissions.
  - `RoleSeeder` no longer gives them to Admin.
- **Business Insights API:**
  - `owner-summary` needs the view permission, `export` needs view + export, and `report-settings` (GET/PUT) needs manage report. The `host-partner` middleware stays.
  - The summary is always built for the host's own login (`FetchOwnerSummary::owner()`, as the weekly report does). A Developer who is host staff would otherwise only see their own clients' commissions.
- **`BusinessInsightsPermissionSeeder`** creates the three permissions and gives them to the host's Developer role and its users. Host roles and users a Developer gave them to keep them. Any other partner's role or user loses them. It is safe to run again. It is also added at the end of `DatabaseSeeder`.

**Behaviour change:** the host owner login loses Business Insights unless it holds the Developer role. Creating a user with another partner's role now returns a 422 on `role_id` (it was a 400).

## Deploy
```
php artisan migrate
php artisan db:seed --class=BusinessInsightsPermissionSeeder
```
Affected users are flagged `is_refresh`, so the admin panel picks up the new permissions on its next request.

## Files changed
- `app/Models/Common/Role.php` — constants, `isReservedName`, `isDeveloper`, `scopeAssignableBy`
- `app/Models/User/User.php` — `isDeveloper`, `scopeHideDevelopersFrom`
- `app/Models/Employee/Employee.php` — `scopeHideDevelopersFrom`
- `app/Models/Common/Permission.php` — `is_developer` fillable/cast, `scopeWithoutDeveloperOnly`
- `app/Rules/NotReservedRoleName.php`, `app/Rules/AssignableRole.php` — new
- `app/Enums/Permission.php`, `app/Enums/PermissionModule.php`, `app/Enums/Attributes/PermissionInfo.php` — Business Insights group, developer flag
- `app/Services/FCH/Common/RolePermission/PermissionService.php` — picker hides developer-only permissions; `grantableQuery`
- `app/Services/FCH/Common/RolePermission/RoleService.php` — grants through `grantableQuery`; constants
- `app/Http/Controllers/Api/V1/FCH/Common/RolePermission/RoleController.php` — role members exclude Developers
- `app/Http/Controllers/Api/V1/FCH/Common/RolePermission/UserRoleController.php` — `assignableBy`, `grantableQuery`
- `app/Http/Controllers/Api/V1/FCH/Common/User/UserController.php`, `app/Services/FCH/Common/User/UserService.php` — scope, `assignableBy`
- `app/Services/FCH/Common/Employee/EmployeeService.php` — scope on list/page/show, `assignableBy`
- `app/Services/FCH/Common/CommonService.php`, `Borrower/BorrowerService.php`, `Appointment/AppointmentService.php` — hide Developers from the pickers
- `app/Http/Requests/.../RolePermission/{Store,Update}RoleRequest.php` — reserved-name rule
- `app/Http/Requests/.../User/{Store,Update}UserRequest.php`, `.../Employee/{Store,Update}EmployeeRequest.php` — `AssignableRole`
- `app/Http/Requests/.../OwnerSummary/{FetchOwnerSummary,ExportOwnerSummary,FetchOwnerReportSettings}.php` — permission checks, `owner()`
- `app/Http/Controllers/Api/V1/FCH/Common/OwnerSummary/OwnerSummaryController.php` — builds for the host's login
- `database/migrations/2026_11_18_000001_add_is_developer_to_permissions_table.php` — new
- `database/seeders/BusinessInsightsPermissionSeeder.php` — new; `PermissionSeeder`, `RoleSeeder`, `DatabaseSeeder` updated
- `lang/en/role_permission.php` — `name_reserved`

## Tests
- New: `tests/Feature/RolePermission/ReservedDeveloperRoleTest.php` (13 cases) and `tests/Feature/RolePermission/DeveloperOnlyPermissionsTest.php` (6 cases).
- Updated:
  - `tests/Unit/Permissions/PermissionEnumTest.php` and the `tests/Fixtures/Permissions/config-permissions.json` snapshot (new group).
  - `tests/Feature/OwnerSummary/*` (access now by permission; a Developer sees the same book as the owner).
  - `tests/Feature/User/UserManagementApiTest.php` (foreign role → 422).
  - `tests/Support/InteractsWithPortalUsers.php` (`makeDeveloper`).
- `DeveloperOnlyPermissionsTest` also covers `the_only_developer_role_is_the_hosts`. `UserManagementApiTest`'s hidden-developer case now uses the host's Developer.
- Full suite: **5954 passed, 2 skipped, 0 failed** (before the host-only change). After it, the affected suites passed: RolePermission, User, Employee, OwnerSummary, Permissions, Appointment, seeders, Partner and CreditProfileRequest (471 tests, then 98 tests).

## Open items
- `canBookWith` (appointments) still accepts a Developer's id when sent directly; only the list hides them.
- The activity log and other screens that show a user's name are not lists of users and were left as they are.
- Older `hasRole('Developer')` checks outside the touched files still use the name. That works because the name is now reserved, but they could move to `isDeveloper()`.
