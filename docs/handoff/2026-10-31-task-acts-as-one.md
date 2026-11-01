# Assigned Task: creator and assignee act as one

2026-10-31

## Problem
An assigned Task gives the creator and the assignee a reminder row each. When the creator rescheduled theirs, only that row moved. The activity's date and the assignee's reminder stayed where they were. Changes to the task (date, due date, priority, status) also told nobody.

## Solution
Two rows are kept on purpose (path ক), because snooze and dismiss stay each person's own. Everything that should be shared is kept in step. This applies only to an assigned Task (`ActivityLog::isAssignedTask()`).
- **Reschedule: creator only.** `ActivityLog::canRescheduleBy()` returns true for any non-task reminder and for the task's creator.
  - The assignee rescheduling gets 403 "Only the task's creator can reschedule it." (`ReminderService::snoozeReminder`, caught in `ReminderController::snooze`).
  - `ReminderResource` returns `can_reschedule`.
- **The creator's reschedule moves the task.** After moving the row used, `ActivityLogService::rescheduleTask()` runs an ordinary `update()` with `activity_date`, `remind_me`, `remind_at` (keeping the lead time picked), and `reminder_preset = REMINDER_PRESET_CUSTOM_TIME_BEFORE` in `REMINDER_TIME_UNIT_MINUTES`.
  - That moves the activity's date and both people's rows, following "Who gets the reminder". The creator's silent row stays silent when only the assignee is reminded.
  - **The due date does not change.**
- **Snooze and dismiss** are unchanged: each person's own.
- **Notifications (bell and mail) to the task's other person.**
  - `ActivityLogService::update()` compares a snapshot of the task before and after (`taskSnapshot()` / `taskChanges()`): date, due date, priority, status.
  - `updateTaskStatus()` reports the status.
  - `TaskAssignmentNotifier::notifyUpdate()` sends to the assignee when the creator made the change, and to the creator when the assignee did. Nobody is told of their own change. Nothing is sent on a wording-only edit, or when the task is assigned, unassigned or reassigned (the new assignee gets the assigned notice as before).
  - New notification types are `activity_log.task_updated.{partner,client,lead,funding_application,funding_request}` (`config/notifications.php`, `$taskUpdated`).
  - The email is `TaskAssignedMail` with `updated_by` and `changes`. It has its own subject ("Task updated: …"), heading and a "What changed" box above the same task card.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: `canRescheduleBy()`, `REMINDER_PRESET_CUSTOM_TIME_BEFORE`, `REMINDER_TIME_UNIT_MINUTES`.
- `app/Services/FCH/Common/Reminder/ReminderService.php`: assignee guard; creator reschedule calls `rescheduleTask()`.
- `app/Http/Controllers/Api/V1/FCH/Common/Reminder/ReminderController.php`: the 403.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `rescheduleTask()`, `taskSnapshot()`, `taskChanges()`, notification calls.
- `app/Services/FCH/Common/ActivityLog/TaskAssignmentNotifier.php`: `notifyUpdate()`.
- `app/Mail/ActivityLog/TaskAssignedMail.php`, `resources/views/emails/activity_log/task_assigned.blade.php`: the updated variant.
- `app/Http/Resources/FCH/Reminder/ReminderResource.php`: `can_reschedule`.
- `config/notifications.php`, `lang/en/activity_log.php`: types and wording.
- Tests: `tests/Feature/ActivityLog/ActivityLogTaskSyncTest.php` (new, 9 tests), a new update-mail render test in `TaskAssignedMailTest.php`, and in `ActivityLogTaskNotificationTest.php` the old "priority/status edit sends nothing" test is now `a_wording_edit_or_unassigning_sends_nothing`.

## Testing
- `vendor/bin/phpunit tests/Feature/ActivityLog`: 128 passed.
- Full suite: 3231 tests OK (20 skipped, unchanged), run before the preset/unit constants were extracted. Only the activity-log tests were re-run after that.

## Open items
- ~~Older code still has preset and unit literals.~~ Done the same day: `ActivityLog` now has `REMINDER_PRESET_DEFAULT`, `REMINDER_PRESET_CUSTOM_DATE_TIME`, `REMINDER_PRESET_MINUTES`, `REMINDER_TIME_VALUE_DEFAULT`, `REMINDER_TIME_UNIT_*` and `REMINDER_TIME_UNIT_MINUTES_EACH`. `ActivityLogResource` and `ActivityLogService` use them, and no literals are left under `app/`. Full suite: 3231 OK.
- Notification times use the actor's `X-Timezone`.
