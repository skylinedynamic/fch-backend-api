# Appointments — stored in UTC, no past times, no clashes (BUG-078, 079, 080)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log, all marked Fixed but with no fix on any branch:
- **BUG-078** (OP-APPT-04): an appointment could be booked for a time already passed today, by the owner and the client; it saved and showed as Expired. `StoreAppointmentRequest` took any `appointment_date`.
- **BUG-079** (OP-APPT-05): two bookings of the same attendee in the same slot were both accepted. `AppointmentService::create()` computed `$isAvailable` and never used it — and that check compared dates only.
- **BUG-080** (OP-APPT-08): the zone defaulted to "UTC (GMT+00:00)" and times showed unconverted. The column held the wall-clock time in whatever zone label the form sent, and every screen printed it as-is.

## Solution
- **Stored in UTC, read in the viewer's zone** (as decided): `App\Support\Appointment\AppointmentTime`, on the existing `toTimezone()` helper:
  - `zone()` reads a zone by name, from the name or the old label ("Asia/Kolkata (GMT+05:30)" → "Asia/Kolkata"); unknown → refused (`timezone:all`).
  - `toUtc()` turns the entered time in the booker's zone (the browser's, sent as `timezone`) into the stored UTC time.
  - `forViewer()` returns it in the zone of the `X-Timezone` header — `AppointmentResource`, both `DashboardAppointmentResource`s and the availability check's conflicts.
  - `inBookedZone()` for what has no viewer: the three appointment emails (now "3:00 PM - 3:30 PM", "Asia/Dhaka (GMT+06:00)") and the activity log lines (zone abbreviation added).
  - `check-availability` and busy slots look at the viewer's own calendar day as a UTC range, not the UTC date.
- **Rules** — `Concerns\AppointmentTimeRules`, used by the store and update requests:
  - a time already passed is refused: "This time has already passed. Choose a later time." An edit keeping a past appointment's own time is allowed.
  - a time overlapping a live (pending / confirmed / rescheduled) appointment of the booker or the attendee is refused, naming whose and when, in the booker's zone: "Ada Lovelace already has an appointment from 9:00 AM to 9:30 AM. Choose another time." / "You already have…". Back-to-back is fine; a cancelled appointment holds nothing; an edit does not clash with itself.
- The unused, date-only `checkTimeSlotAvailability()` is removed.
- **Migration** `2026_10_24_120000_store_appointment_times_in_utc`: rows still carrying the old label ("Zone (GMT±hh:mm)") are moved from that zone to UTC and keep the zone by name (UTC-labelled rows — all 6 locally — only get the name); `appointment_details` re-derives its date. Rows saved by the new code (zone by name, already UTC) are never touched, so it is safe whenever it runs and safe to run twice. Each time is converted by its zone's rules on that date (daylight saving included), not the label's fixed offset. `down()` puts the wall-clock time and the label back.

## Files changed
- `app/Support/Appointment/AppointmentTime.php` — new.
- `app/Http/Requests/Api/V1/FCH/Common/Appointment/Concerns/AppointmentTimeRules.php` — new.
- `app/Http/Requests/Api/V1/FCH/Common/Appointment/StoreAppointmentRequest.php`, `UpdateAppointmentRequest.php` — use it; `timezone` validated.
- `app/Http/Resources/FCH/Appointment/AppointmentResource.php`, `app/Http/Resources/{Borrower/Dashboard,FCH/Common/Dashboard}/DashboardAppointmentResource.php` — viewer's zone.
- `app/Http/Controllers/Api/V1/FCH/Common/Appointment/AppointmentController.php` — availability conflicts in the viewer's zone.
- `app/Services/FCH/Common/Appointment/AppointmentService.php` — viewer's day as a UTC range; dead check removed.
- `app/Services/{Borrower/ActivityLog/BorrowerActivityLogService,FCH/Common/ActivityLog/ActivityLogService}.php` — log time in the booked zone.
- `resources/views/emails/appointment/{create,update,delete}.blade.php` — time and zone label in the booked zone.
- `lang/en/appointment.php` — `time_in_past`, `time_taken_by`, `time_taken_by_you`, `the_attendee`.
- `database/migrations/2026_10_24_120000_store_appointment_times_in_utc.php` — new.
- Tests: `tests/Feature/Appointment/AppointmentTimeAndClashTest.php` (new, 18), `tests/Feature/Appointment/StoreAppointmentTimesInUtcMigrationTest.php` (new, 3).

## Tests
- `StoreAppointmentTimesInUtcMigrationTest` (7): label → UTC; UTC only renamed; a row already saved in UTC left alone and running twice not shifting twice (both failed first, then fixed); daylight saving by date; down and up again round-trip.
- `AppointmentTimeAndClashTest`: 14 failed first (stored local, no zone check, past and clashes accepted, read unconverted, email in UTC), pass after; 4 that pinned what must keep working (back-to-back, cancelled, edit itself, edit a past one) passed throughout.
- `tests/Feature/Appointment`, `tests/Integration/Mail`, `tests/Feature/ActivityLog`: 108 passed. Full suite: see the session report.
- Real API (client-fch E2E `appointment-time-rules-create.spec.ts`, MySQL): stored UTC, read back per zone, clash and past refused with their messages.

## Open items
- **The migration** runs with the deploy (both deploy workflows run `php artisan migrate --force`); locally run `php artisan migrate`. Between the new code going live and the migration finishing, old rows labelled with a non-UTC zone read back shifted — seconds on a normal deploy.
- The SMS/reminder paths do not print appointment times today; if one is added, use `AppointmentTime::inBookedZone()`.
- Mark BUG-078, 079, 080 in the QA defect log In Progress, then Fixed after the stage retest.
