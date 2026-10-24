# New permission "manage credit profile requests" for roles 1 and 2 (BUG-097)

**Date:** 2026-10-25
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-097** (OP-ROLE-06): a host user whose role only has "view leads" saw the **Intake & Review** menu.

The cause: Intake & Review › **Request Credit Profile** had no permission at all, only "host only". No permission for it existed in `config/permissions.php`, so roles could not grant or withhold it.

## Solution
- **New permission** in `config/permissions.php` › `intake and review`: `manage credit profile requests` (host only), under the key `manage_credit_profile_requests`.
- **New seeder:** `CreditProfileRequestPermissionSeeder` gives it to **roles 1 and 2** (the host's Developer and Admin) and to the users holding them, and sets `is_refresh`. It is safe to run again.
- **Where the name comes from:** each seeder reads its permission from `config/permissions.php` through its full config path, `config('permissions.<module>.permissions.<key>')`. The permission name is never written in the seeder.
- **Shared code:** the granting lives in a new abstract `GiveHostRolesPermissionSeeder`. `PartnerConfigurationPermissionSeeder` now extends it too, with the same behaviour as before.
- **Checks:** the admin panel checks the permission on the menu and the route. **As decided, there is no server-side check:** the `credit-profile-requests` API routes and the dashboard API are unchanged.

**On each environment:**
```
php artisan db:seed --class=CreditProfileRequestPermissionSeeder
```
Before running it on stage or production, check that roles 1 and 2 are the host's Developer and Admin there.

## Files changed
- `config/permissions.php`: the new permission.
- `database/seeders/GiveHostRolesPermissionSeeder.php`: new abstract base. A subclass gives only its `definition()` from the config; the base finds the module in `config/permissions.php` by the permission's name.
- `database/seeders/CreditProfileRequestPermissionSeeder.php`: new.
- `database/seeders/PartnerConfigurationPermissionSeeder.php`: now extends the base.
- `tests/Feature/CreditProfileRequest/CreditProfileRequestPermissionSeederTest.php`: new, 3 tests.

## Tests
- **New seeder test, 3 tests** (all three failed first because the seeder didn't exist):
  - roles 1 and 2 and their users get the permission; role 3 doesn't
  - the permission sits in the `intake and review` module
  - running the seeder twice adds nothing
- The existing `PartnerConfigurationPermissionSeederTest` still passes after the refactor: 6 passed across both seeder tests.
- `php -l` is clean on all three seeders.
- **Local database:** the permission briefly existed as "view credit profile requests" during this work, and that row was renamed in place. The seeder then ran: "'manage credit profile requests' given to 2 role(s) and 2 user(s)."

## Open items
- **No API permission check** on `credit-profile-requests`, as decided. A user can still call the API directly.
- **BUG-125** (the same role opening Lenders, Tiers and so on by typing the URL) is not covered here.
- **QA sheet:** BUG-097 → Fixed after the seeder runs on stage and the stage retest passes.
