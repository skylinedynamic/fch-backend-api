# Task status changed from its reminder

2026-10-31

## Problem
An assigned Task's status (Open / In Progress / Complete) could only be changed by editing the whole activity, which only its creator may do. The assignee had no way to report progress. The reminder screens (popup, Task & Reminder Details, reminders list) didn't show the task at all.

## Solution
- New endpoint `PATCH|POST common/activity-logs/{id}/task-status` with `{ task_status }`. It changes only `properties.task.status`.
  - Allowed for the task's **creator or assignee**, via `ActivityLog::canUpdateTaskStatusBy()`. Anyone else gets 403 "Only the task's creator or assignee can change its status."
  - An activity without an assigned task gets 422 "This activity is not an assigned task." An invalid value gets 422 "Status must be Open, In Progress or Complete." (`UpdateActivityLogTaskStatusRequest`).
  - After saving, both people's reminders (`reminder_id`, `task_reminder_id`) go through `ReminderBroadcaster::updated()`, so open popups update over Reverb.
- `ReminderResource` gains `task` (status/label, priority/label, due date in the viewer's timezone plus `due_date_display`, `assigned_to_name`, `assigned_by_name`; null when there is no assigned task) and `task_can_update_status`. `activity_can_edit` is unchanged: the assignee still cannot edit the whole activity.
- `Reminder::clientRelations()` now eager-loads `activityLog.causer` for "Assigned By", avoiding one query per reminder row.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: `isAssignedTask()`, `canUpdateTaskStatusBy()`.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/UpdateActivityLogTaskStatusRequest.php`: new.
- `app/Http/Controllers/Api/V1/FCH/Common/ActivityLog/ActivityLogController.php`: `updateTaskStatus()`.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `updateTaskStatus()` plus the broadcast.
- `routes/common.php`: route.
- `lang/en/activity_log.php`: `task_status_updated`, `task_status_update_failed`.
- `app/Http/Resources/FCH/Reminder/ReminderResource.php`: `task`, `task_can_update_status`, `taskSummary()`.
- `app/Models/Common/Reminder.php`: eager-load `activityLog.causer`.
- `tests/Feature/ActivityLog/ActivityLogTaskStatusTest.php`: new, 8 tests.

## Testing
- `vendor/bin/phpunit tests/Feature/ActivityLog`: 109 passed.
- Full suite: 3207 tests OK (20 skipped, unchanged).

## Open items
- `assigned_to_name` is still one small query per task row in the reminders list (only rows with an assigned task).
- Changing the status sends no notification. Only open popups refresh.
