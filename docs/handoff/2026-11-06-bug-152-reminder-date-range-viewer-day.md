# BUG-152: Task & Reminders date range uses the viewer's day

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2
**Author:** awlad

## Problem
On the Owner portal's Task & Reminders page (`/reminders`), the Date Range filter used the UTC day instead of the viewer's day. QA was in Asia/Dhaka (UTC+6) on 01-Nov-2026, and under "Showing reminders for today" (01 Nov – 01 Nov) the list showed:
- a reminder for 02-Nov 01:03 AM (local), which belongs to tomorrow;
- but not a reminder for 01-Nov 01:17 AM (local), which belongs to today. That one appeared only after "Show all dates".

`Reminder::scopeDateBetween` compared the stored UTC `set_date_time` / `send_at` with `'<date> 00:00:00'` to `'<date> 23:59:59'` and never applied the viewer's time zone. As a result, every picked range was shifted by the viewer's UTC offset. For US Eastern users, "today" started at 8 PM the evening before.

## Solution
`scopeDateBetween` now takes a time zone. It turns the start and end dates into the start and end of those days in that zone, then converts both to UTC before comparing. `ReminderService::getReminders` passes the request's `X-Timezone` header, which both portals already send. If the header is missing or not a valid IANA zone, the app's time zone (UTC) is used, which matches the old behaviour. This follows the same pattern as the BUG-120 date range fix in `RequestApplicationService`.

What has not changed: a reminder is still listed when either its activity time (`set_date_time`) or its reminder time (`send_at`) falls in the range. No frontend change is needed. The admin `ReminderList.tsx` already sends local `Y-m-d` dates, and `apiClient` sets `X-Timezone`.

## Files changed
- `app/Models/Common/Reminder.php`: `scopeDateBetween` takes an optional `$timezone` and builds the bounds from whole local days converted to UTC. Also imports `Illuminate\Support\Carbon`.
- `app/Services/FCH/Common/Reminder/ReminderService.php`: passes `X-Timezone` to `dateBetween`.

## Tests
- Added `tests/Feature/Reminder/ReminderDateRangeTimezoneTest.php`, which pins four cases:
  - With the clock at Dhaka 01-Nov 01:00, "today" lists only 01-Nov local reminders: the 02-Nov 01:03 one is excluded and the 01-Nov 01:17 one is included.
  - A range of two or more days covers whole local days.
  - With no header, the UTC day is used.
  - With an invalid header, the code falls back to UTC.
- Red first: before the fix, two of the four failed, showing the bug exactly as reported.
- `tests/Feature/Reminder` plus `ActivityLogTaskReminderTest`: 39 tests, all passed.
- Full PHPUnit suite: 4926 tests passed, 2 skipped (already skipped before this change).

## Open / follow-up
- The QA "Expected" column describes filtering by activity date only. The filter still also matches on reminder time (`send_at`), so an activity at 00:10 with a 30-minute reminder also appears on the previous day. That is existing behaviour; change it only if product confirms it should be activity date only.
- The Defect Log already shows Status "Fixed" for BUG-152, so QA should retest on stage after deploy.
