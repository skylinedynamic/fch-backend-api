# get-all-users: active staff only for assign pickers, and no inactive Task assignee

**Date:** 2026-10-28
**Branch:** feature/activity-task-assign
**Author:** Awlad Hossain

## Problem
- `POST common/get-all-users` returned the whole team, inactive users included, so every "assign to" picker in the admin panel offered them. These are Task Assigned To, Client Assign to, Lead Owner and Partner Agent.
- A Task could also be assigned to an inactive user through the API.

## Solution
- **`get-all-users`** (`CommonService::getAllUsers($partnerId, $activeOnly, $keepIds)`) takes two optional fields:
  - `active_only` (boolean): only users with `status = active`.
  - `keep_ids` (int array): users kept even if inactive, so a record's current assignee still shows by name.

  Kept ids are still limited to the team (`User::teamOf`), so they can never bring in someone from another partner, or a borrower. Without `active_only`, the list is unchanged, which the list filters rely on.
- **Task assignment** (`ValidatesTaskAssignment`) refuses an inactive assignee with "The selected staff member is inactive and cannot be assigned this task." An Edit may keep the saved assignee (`savedTaskAssigneeId()` on `UpdateActivityLogRequest`) even if that user has since become inactive.

## Files changed
- `app/Services/FCH/Common/CommonService.php`: `active_only` / `keep_ids` filter.
- `app/Http/Controllers/Api/V1/FCH/Common/CommonController.php`: reads and sanitises the two fields. Non-numeric ids are dropped.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/Concerns/ValidatesTaskAssignment.php`: the inactive check, `savedTaskAssigneeId()` and `isActiveUser()`.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/UpdateActivityLogRequest.php`: `savedTaskAssigneeId()` from `properties.task.assigned_to`.

## Testing
- `tests/Feature/ActivityLog/ActivityLogTaskAssignmentTest.php`: +3 tests.
  - Inactive refused.
  - `active_only` with and without `keep_ids`, and no filter.
  - Kept ids never reach outside the team.
- `tests/Feature/ActivityLog/ActivityLogTaskEditTest.php`: +1 test. A saved inactive assignee may stay, but can't be newly picked.
- The new tests were red first, except the outside-the-team guard, which already held.
- `tests/Feature/ActivityLog` plus the four other suites that call get-all-users (BorrowerCommonRouteAccess, BorrowerRecordsApi, LeadOwnerTeam, PartnerAccountApi): 202 passed.

## Open items
- Client `assign_id`, lead `salesperson_id` and partner Agent are not refused server-side when the user is inactive. Only the Task assignee is.
