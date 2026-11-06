# BUG-189: an appointment change tells the other person, not the one who made it

**Date:** 2026-11-06

## Problem
A client booked an appointment with Host Admin, and the host then rescheduled it. The "Appointment updated" bell (and email) went to the **host**, who had made the change, and the client got nothing.

`AppointmentService::update()` and `delete()` called `sendNotification()` with no recipient, so it always went to `assigned_for`. When the client books, `assigned_for` is the host. When the host books, it is the client, which is why a host-created appointment did notify the client. `cancel()` already told the other party (R2 BUG-046).

## Solution
- New `AppointmentService::otherParty($appointment, $actor)`: the organiser (`scheduled_by`) when the attendee acted, otherwise the attendee. With no actor (a job), it falls back to the attendee, as before.
- `update()` and `delete()` notify `otherParty(auth user)`. `cancel()` uses the same helper.
- The bell and the email go together through the Notifier, so both reach the other person.

## Files changed
- `app/Services/FCH/Common/Appointment/AppointmentService.php`
- `tests/Feature/Appointment/AppointmentChangeNotificationTest.php` (new, 4 tests)

## Testing
- `AppointmentChangeNotificationTest`, 4 tests:
  - The host reschedules a client-booked appointment: client told. Red first.
  - The host deletes a client-booked appointment: client told. Red first.
  - The host reschedules its own booking: client told. Already correct; kept as coverage.
  - The client reschedules its own booking: host told. Already correct; kept as coverage.
  - Each one checks that exactly one email goes, to the right person.
- `tests/Feature/Appointment` + `tests/Integration`: 559 passed.
- Local MySQL, inside a rolled-back transaction (Mail and Notification faked): host user 1 reschedules an appointment booked by client user 5. The bell (DynamicNotification) and the email went to user 5 only. No probe data was left.

## Open items
- The notification's date is the stored UTC date (`M j, Y`), not the booked time zone. Unchanged here.
- Not committed.
