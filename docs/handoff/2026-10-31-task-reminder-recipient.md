# Task reminder: who gets it

2026-10-31

## Problem
On a Task assigned to someone else, the reminder the activity set always went to both the author and the assignee. The admin panel now asks who gets it: the creator, the assignee or both.

## Solution
- New optional request field `reminder_recipient` on `POST/PUT common/activity-logs`, one of `ActivityLog::REMINDER_RECIPIENTS` (`creator`, `assignee`, `both`). The rule is in `ValidatesTaskAssignment`, and its message is "Please choose who gets the reminder." The value is stored in `properties.reminder_recipient`.
- `ActivityLog::reminderRecipientFor($props, $causerId)` returns the effective choice: the stored value (`both` when none) on a Task assigned to someone else, and `creator` in every other case. The resource returns it as `reminder_recipient`.
- **Creator's reminder** (`ActivityLogService`): it is not created on store when the choice is `assignee`. On update, switching to `assignee` deletes it and switching back re-creates it.
- **Assignee's reminder** (`taskRemindAt`): when the activity sets a reminder and the choice is `creator`, the assignee gets none. With no reminder set, the assignee is still reminded on the due day, as before.
- **Bug fixed along the way:** `ActivityLogResource` found the "linked reminder" by `activity_log_id`, which also matched the assignee's own reminder. A Task without a reminder of its own therefore reported `remind_me: true`. Both fallback lookups now exclude `task_reminder_id`.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: `REMINDER_RECIPIENT_*` constants and `reminderRecipientFor()`.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/Concerns/ValidatesTaskAssignment.php`: rule and message.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: stores the choice, `creatorGetsReminder()`, and skips or removes the creator's reminder; `taskRemindAt()` respects `creator`.
- `app/Http/Resources/FCH/ActivityLog/ActivityLogResource.php`: `reminder_recipient` output; the linked-reminder fallbacks skip the assignee's copy.
- `tests/Feature/ActivityLog/ActivityLogTaskReminderRecipientTest.php`: new, 8 tests covering the default, assignee only, creator only, no reminder set, self-assigned, switching on edit, an invalid value, and the resource bug.

## Testing
- `vendor/bin/phpunit tests/Feature/ActivityLog`: 101 passed.
- Full suite: 3199 tests OK (20 skipped, unchanged).

## Open items
- None known.
