# Activity log: assign a Task to a staff member

**Date:** 2026-10-27
**Branch:** feature/activity-task-assign
**Author:** Awlad Hossain

## Problem
In the admin portal (Partners, Leads, Clients, and Intake & Review › Funding Application › Activity log › New Event), a manual **Task** activity couldn't be given to a staff member. There was also no due date separate from the activity date, and no priority or status. The store endpoint accepted only a bare `assigned_to`, which was checked against every user on the platform.

## Solution
`POST /api/v1/common/activity-logs` takes a task assignment:
- `assign_task`: `1`/`true` turns it on
- `assigned_to`: a user on the **caller's own team** (`User::teamOf($caller->partner)`, the same list `common/get-all-users` returns). A partner can't assign host staff, and nobody can assign a borrower or another partner's user. This check now applies whenever `assigned_to` is sent.
- `task_due_at`: wall-clock time in the `X-Timezone` timezone, which must be in the future. It's stored in UTC and is separate from `activity_date`.
- `task_priority`: `low` | `medium` | `high`
- `task_status`: `open` | `in_progress` | `complete`

All four are required once `assign_task` is on. Assigning is refused unless `reference` is `Task`; that error is returned under `errors.assign_task`.

The fields are stored on the activity, in `properties.task` (`assigned_to`, `due_at` UTC, `priority`, `status`) and in `properties.assigned_to`. The response has a new `task` key: `{ assigned_to, assigned_user: {id, name}, due_at (viewer's timezone), priority, priority_label, status, status_label }`. It's `null` when nothing was assigned. No migration was needed.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: `TASK_REFERENCE`, `TASK_PRIORITY_*` and `TASK_STATUS_*` constants with their labels
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/StoreActivityLogRequest.php`: rules and messages for the task fields; assignee must be on the caller's team; checks for Task only and a future due date
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `store()` saves `properties.task` when assigning
- `app/Http/Resources/FCH/ActivityLog/ActivityLogResource.php`: `task` in the response (`taskDetails()`)

## Tests
- Added: `tests/Feature/ActivityLog/ActivityLogTaskAssignmentTest.php` (9). Eight of them failed before the change. They cover:
  - host assigns a task, with the due date stored in UTC and shown back in the viewer's timezone
  - partner assigns to their own team
  - the four fields are required
  - invalid priority or status is refused
  - an assignee outside the team is refused (partner user, borrower)
  - a partner can't assign host staff
  - a past due date is refused
  - only a Task can be assigned
  - an unassigned task has `task: null`
- Suites run:
  - `tests/Feature/ActivityLog`, `tests/Unit/ActivityLog` and `tests/Feature/Reminder`: 61/61 pass
  - Full suite: 3120 passed, 2 skipped, 1 failed. The failure is `Tests\Unit\Assistant\NavigationMapTest` (borrower route list), which also fails with these changes stashed, so it was already failing.

## Open / follow-up
- `UpdateActivityLogRequest` / `performUpdate()` ignore the task fields, so an assignment can't be edited yet.
- The resource looks up the assignee per row, so a long timeline means one query per assigned task. That's fine at today's page size (15); eager-load the users if it grows.
- No notification to the assignee yet.
- Admin portal handoff of the same date: `react-fch/docs/handoff/2026-10-27-activity-log-assign-task-to-staff.md`.
