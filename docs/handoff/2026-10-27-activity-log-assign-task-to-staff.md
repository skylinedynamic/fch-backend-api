# Activity log: assign a Task to a staff member

**Date:** 2026-10-27 (feature complete 2026-10-28)
**Branch:** feature/activity-task-assign
**Author:** Awlad Hossain

## Problem
In the admin portal (Partners, Leads, Clients, and Intake & Review › Funding Application / Funding Request › Activity log), a manual **Task** activity couldn't be given to a staff member. There was also:
- no due date separate from the activity date, and no priority or status
- no way to change the assignment afterwards
- no notification to the person it was given to

The store endpoint accepted only a bare `assigned_to`, which was checked against every user on the platform.

## Solution

### Request (store and update)
`POST /api/v1/common/activity-logs` and `PUT|POST /api/v1/common/activity-logs/{id}` both take:
- `assign_task`: `1`/`true` assigns the task. On update, `0` un-assigns it.
- `assigned_to`: someone on the **team of the partner that owns the record**, i.e. `User::teamOf(...)`, the same list `common/get-all-users` returns for that `partner_id`.
  - For the host, that is the lead's, client's, funding request's or funding application's `partner_id`. On a Partner's page it is the host's own staff.
  - Anyone else may only pick from their own team.
  - A borrower is never eligible.
- `task_due_date`: a calendar day, `Y-m-d`, stored as picked. It must be today or later in the `X-Timezone` timezone. On update, an already-saved past day may be kept.
- `task_priority`: `low` | `medium` | `high`
- `task_status`: `open` | `in_progress` | `complete`

All four are required once `assign_task` is on. Assigning is refused unless the reference (sent, or saved on update) is `Task`; that error is under `errors.assign_task`. The rules live in one place, `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/Concerns/ValidatesTaskAssignment.php`, used by both requests.

### Storage and response
- **Stored** in `properties.task` (`assigned_to`, `due_date`, `priority`, `status`) and in `properties.assigned_to`. No migration was needed.
- **Response:** a `task` key, `{ assigned_to, assigned_user: {id, name}, due_date, priority, priority_label, status, status_label }`, or `null`.
- **Cleared** when it is un-assigned, or when an update moves the reference away from Task. An update that sends no `assign_task` leaves the task untouched.

### Telling the assignee
`App\Services\FCH\Common\ActivityLog\TaskAssignmentNotifier` runs after the save commits. It runs on create, and on update only when the task goes to someone new.
- **Email:** `App\Mail\ActivityLog\TaskAssignedMail`, view `emails.activity_log.task_assigned`. It contains:
  - who assigned it and on which record
  - the subject
  - the assignee's initials and name
  - the due day
  - coloured priority and status badges
  - the description as plain text
  - a **View Task** link to the record in the assignee's own partner portal

  It is queued and sent from the assignee's partner's mailbox.
- **Bell:** one notification type per record, because a placeholder value can't carry a path:
  - `activity_log.task_assigned.{partner|client|lead|funding_application|funding_request}` in `config/notifications.php`
  - category `reminder`, icon `ri-task-line`
  - channels: database, broadcast and mail

  The admin portal already colours the `reminder` category.
- **When nothing is sent:** for a task assigned to oneself, or one that isn't assigned. Any failure is logged (`activity_log.task_assigned_notify_failed`) and never fails the save.

### The assignee's own reminder
A separate `reminders` row owned by the assignee. Its id is in `properties.task_reminder_id`, and the time it was set for is in `task_reminder_at`.
- **When it fires:** at the activity's reminder time if `remind_me` is on, next to the author's own reminder. Otherwise 09:00 on the due day, in the assigner's timezone (`ActivityLog::TASK_DUE_REMINDER_TIME`).
- **Why a separate row:** each person snoozes or dismisses their own.
- **On edit:**
  - It is rescheduled only when its time changes, so a wording edit keeps a snoozed or dismissed state.
  - A new assignee gets a fresh row.
  - Un-assigning, leaving Task, or deleting the activity removes it.
- **Built by** `ReminderService::upsertForUser()`.
- **Legacy safety:** the old subject-matching lookup for rows without `reminder_id` now skips the assignee's row (`findLinkedReminder`).

## Files changed
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/Concerns/ValidatesTaskAssignment.php` (new): the shared rules, messages and checks
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/StoreActivityLogRequest.php`: uses the trait; team from the record in the request
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/UpdateActivityLogRequest.php`: uses the trait; team, reference and saved due day from the activity being edited
- `app/Models/ActivityLog/ActivityLog.php`: task reference, priority and status constants and labels; `TASK_DUE_REMINDER_TIME`
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`:
  - `applyTaskAssignment`
  - `syncTaskReminder` and `taskRemindAt`
  - notification on store and on reassignment
  - the delete path removes the assignee's reminder
- `app/Services/FCH/Common/ActivityLog/TaskAssignmentNotifier.php` (new)
- `app/Services/FCH/Common/Reminder/ReminderService.php`: `upsertForUser()`
- `app/Http/Resources/FCH/ActivityLog/ActivityLogResource.php`: the `task` key
- `app/Mail/ActivityLog/TaskAssignedMail.php` and `resources/views/emails/activity_log/task_assigned.blade.php` (new)
- `config/notifications.php`: the five `activity_log.task_assigned.*` types
- `lang/en/activity_log.php`: mail, bell and log strings

## Testing
- `tests/Feature/ActivityLog/Concerns/BuildsTaskAssignmentFixtures.php` (new): shared people, records and payloads
- `ActivityLogTaskAssignmentTest` (11, rewritten):
  - client, lead and Partner-page teams; a partner's own team only
  - required fields, options, day-only, today allowed but not past
  - Task only; unassigned has no task
- `ActivityLogTaskEditTest` (11, new):
  - change all four fields
  - un-assign; assign from edit; leaving Task drops it; an edit without task fields keeps it
  - team rule on edit; Partner page
  - a saved past day may stay but a new one can't be past
  - validation; Task only; someone else's activity is refused
- `ActivityLogTaskNotificationTest` (10, new):
  - mail and bell content without a reminder
  - nobody else is notified; none for self or unassigned
  - link and wording per record; the five types are configured
  - reassignment notifies only the new person; a same-assignee edit sends nothing; assigning from edit notifies
  - a notifier failure doesn't fail the save
- `ActivityLogTaskReminderTest` (11, new):
  - due-day 09:00 reminder; two rows with a reminder
  - the assignee dismissing theirs leaves the author's
  - none for self or unassigned
  - rescheduled on a new due day; moves to a new assignee
  - reminder on and off; a wording edit keeps snoozed or dismissed
  - removed on un-assign, leaving Task, or delete
  - the legacy lookup never takes the assignee's row. Confirmed to fail without the guard.
- `TaskAssignedMailTest` (6, new): details, badge colours, link, subject, escaping, missing details left out
- **Full suite:** 3167 passed, 2 skipped, 1 failed. The failure is `Tests\Unit\Assistant\NavigationMapTest`, which also fails without these changes.

## Open items
- Restart `queue:work` after deploying, for the new Mailable.
- Notifications go only to the new assignee. Status or priority changes notify nobody.
- No "my tasks" list or filter yet.
