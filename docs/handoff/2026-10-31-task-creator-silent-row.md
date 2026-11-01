# Task creator always sees an assigned task in Task & Reminders

2026-10-31

## Problem
The Task & Reminders list only shows reminders the viewer owns (`Reminder::createdByUser`). When a Task was assigned to someone else and "Who gets the reminder" was **Assignee**, or no reminder was set at all, the creator had no reminder row. The task never appeared in their list, so they couldn't follow it or change its status from there.

## Solution
The creator of a Task assigned to someone else now always has a row in their list. When they aren't being reminded themselves, that row is **silent**:
- `reminders.is_reminder = false`. The column already existed (default `true`) and nothing read it before. A silent row is listed but never due, so it never pops up or notifies.
  - `Reminder::scopeDue()` requires `is_reminder = true`. This covers `/reminders/due` and the `task:reminder-check` sweep.
  - `DispatchReminderDueJob` skips a silent row.
  - `isShowable()` / `wasShowable()` are false for one, so no Reverb push reaches the popup.
- `ActivityLogService::syncCreatorTaskCopy()` (on store and update) keeps it in step. It is needed when the task has an assignee other than the creator and the creator has no reminder of their own (`reminder_id` empty).
  - Its time is the reminder time if one is set, otherwise the due-day time (`dueDayRemindAt()`, split out of `taskRemindAt()`).
  - Stored as `properties.creator_task_reminder_id` and `creator_task_reminder_at`.
  - Deleted when it is no longer needed: the creator gets a reminder, the task is unassigned or leaves Task, or the activity is deleted.
- Snoozing or rescheduling a silent row turns it into a real reminder (`snoozeReminder()` already sets `is_reminder = true`). A later change to the activity's time makes it silent again.
- `ReminderResource` returns `is_reminder`. In `ActivityLogResource` and `findLinkedReminder()`, the linked-reminder fallback skips the silent row, so the activity never reports a reminder the creator didn't set.

| Who gets the reminder | Creator's row | Assignee's row |
|---|---|---|
| Both | real | real |
| Me | real | none |
| Assignee | **silent** | real |
| No reminder set | **silent** (due day) | real (due day) |

## Files changed
- `app/Models/Common/Reminder.php`: `isShowable()` / `wasShowable()` / `scopeDue()` honour `is_reminder`.
- `app/Jobs/Reminder/DispatchReminderDueJob.php`: skips silent rows.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `syncCreatorTaskCopy()`, `findCreatorTaskCopy()`, `dueDayRemindAt()`; delete and linked-reminder handling.
- `app/Http/Resources/FCH/Reminder/ReminderResource.php`: `is_reminder`.
- `app/Http/Resources/FCH/ActivityLog/ActivityLogResource.php`: the fallback skips the silent row.
- Tests: `tests/Feature/ActivityLog/ActivityLogTaskCreatorCopyTest.php` (new, 9 tests); `ActivityLogTaskReminderTest.php` and `ActivityLogTaskReminderRecipientTest.php` updated for the creator's silent row.

## Testing
- `vendor/bin/phpunit tests/Feature/ActivityLog`: 118 passed.
- Full suite: 3216 tests OK (20 skipped, unchanged).

## Open items
- Tasks created before this change get their creator's row the next time the activity is saved. There is no backfill command.
- When "Me" is chosen the assignee has no row, so they can't follow the task from Task & Reminders (unchanged).
