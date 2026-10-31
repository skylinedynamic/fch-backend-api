# Task due date with a time

2026-10-31

## Problem
"Want to Assign" on a Task activity (`POST/PUT common/activity-logs`) accepted only a calendar day for `task_due_date` (`Y-m-d`). The admin panel now needs a due **date and time**.

## Solution
- **Validation** (`ValidatesTaskAssignment`): `task_due_date` is now `date_format:Y-m-d H:i:s` and must not be in the past in the `X-Timezone` timezone. A time later today is fine. The message is "The due date is not a valid date and time." On edit, a saved due date that has passed may still be kept, as before.
- **Storage** (`ActivityLogService::applyTaskAssignment`): `properties.task.due_date` is converted to UTC with `localToUtc()`, the same as `activity_date`.
- **Output and comparison**: the new `ActivityLog::taskDueDateLocal()` turns the stored UTC value into the viewer's wall-clock time. It is used by `ActivityLogResource` (`task.due_date`), `UpdateActivityLogRequest::savedTaskDueDate()` and `TaskAssignmentNotifier`. Rows saved before this change hold a bare day (`Y-m-d`, 10 chars). Those are read as that day's `00:00:00` and are not shifted.
- **Assignee reminder** (`taskRemindAt`): still 09:00 on the due day when the activity has no reminder of its own, but never later than the due time. A task due at 08:15 reminds at 08:15.
- **Notification / email**: the due date reads `M j, Y g:i A`.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: `taskDueDateLocal()` helper; `TASK_DUE_REMINDER_TIME` doc updated.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/Concerns/ValidatesTaskAssignment.php`: date-time format, past-time check.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/UpdateActivityLogRequest.php`: saved due date compared in the viewer's timezone.
- `app/Http/Resources/FCH/ActivityLog/ActivityLogResource.php`: `task.due_date` in the viewer's timezone.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: due date stored in UTC; assignee reminder capped at the due time.
- `app/Services/FCH/Common/ActivityLog/TaskAssignmentNotifier.php`: due date shown with its time.
- `tests/Feature/ActivityLog/Concerns/BuildsTaskAssignmentFixtures.php`: `dueDate()` returns 3:30 PM local; new `dueDateUtc()`; `dueMorningUtc()` takes the day part.
- `tests/Feature/ActivityLog/ActivityLogTaskAssignmentTest.php`: stored in UTC, time required, "later today but not earlier".
- `tests/Feature/ActivityLog/ActivityLogTaskEditTest.php`: past saved value stored as UTC; new test for a legacy day-only due date.
- `tests/Feature/ActivityLog/ActivityLogTaskReminderTest.php`: new test for a task due before 09:00.
- `tests/Feature/ActivityLog/ActivityLogTaskNotificationTest.php`: bell text includes the time.

## Testing
- `vendor/bin/phpunit tests/Feature/ActivityLog`: 93 passed.
- Full suite: 3191 tests OK (20 skipped, unchanged).

## Open items
- Day-only due dates already saved on `stage` are converted to UTC the next time the task is saved with a due date.
- The notifier formats the time in the assigner's `X-Timezone`. The assignee's own timezone is not known server-side.
