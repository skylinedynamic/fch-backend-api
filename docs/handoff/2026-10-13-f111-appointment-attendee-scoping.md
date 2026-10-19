# Appointment attendee scoping (F111, was O20)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F111 (was O20)

## Problem
`POST /common/appointments/fetch-selectable-users` gives each role a scoped list of people it may book with:
- a borrower: their partner and their advisor;
- a partner: its own team, or the host;
- staff: their partner and their assigned borrowers;
- the host owner: its team, or every partner.

The write did not apply that list. `StoreAppointmentRequest` and `UpdateAppointmentRequest` accepted `assigned_for` as `exists:users,id`, which is **any user on the platform**. Borrowers reach these routes too. So, by sending another partner's user id:
- the meeting landed in that user's appointment list;
- it counted against their availability, so their slots could be blocked;
- they got a bell notification and an email branded with the caller's partner.

`entity_type`/`entity_id` were also unchecked, so a meeting could be tied to another partner's borrower.

## Solution
- **One rule for the list and the write.** `AppointmentService::selectableUsersQuery()` is the bookable-people rule, extracted unchanged from `getSelectableUsers()`, which now uses it.
- **`canBookWith($caller, $userId)`** checks the user is on that list with the meeting-with switch off or on. The portals send the switch, but the API does not rely on it.
- **`App\Rules\BookableAttendee`** applies on store and update and returns 422 `appointment.assigned_for_not_bookable`. On update, keeping the appointment's current attendee is always allowed. This matters because a partner editing a meeting a borrower booked with it is its own attendee, and it is never on its own list.
- **`App\Rules\AttendeesEntity`** requires that an entity, when given, is the attendee's own record (422 `appointment.entity_not_attendees`). Both portals send the picked user's entity.
- **Update order.** `UpdateAppointmentRequest` loads the appointment scoped to the two people on it before validating. An outsider still gets the controller's 404, not a 422 that would confirm the meeting exists.
- **No portal change.** react-fch and borrower-fch only offer people from the list.

## Files changed
- `app/Services/FCH/Common/Appointment/AppointmentService.php`
- `app/Rules/BookableAttendee.php` (new)
- `app/Rules/AttendeesEntity.php` (new)
- `app/Http/Requests/Api/V1/FCH/Common/Appointment/StoreAppointmentRequest.php`
- `app/Http/Requests/Api/V1/FCH/Common/Appointment/UpdateAppointmentRequest.php`
- `lang/en/appointment.php`

## Tests
- `Feature/Appointment/AppointmentApiTest`, written to fail first and then passing:
  - `a_booking_with_someone_off_my_bookable_list_is_refused_and_no_one_is_notified`: a borrower and a partner try another partner's owner and another partner's borrower. Each gets 422, no appointment is created, no notification row is written and no mail is sent.
  - `a_booking_cannot_point_at_someone_elses_entity`
  - `a_meeting_cannot_be_moved_to_someone_off_my_list`
- Guard against regressions, passing before and after: `an_unchanged_attendee_and_the_hosts_partner_meetings_still_work`.
  - The partner edits a meeting a borrower booked with it.
  - The host books with another partner.
- Existing test kept as is: `someone_not_on_a_meeting_cannot_open_move_or_cancel_it` (still 404). It caught the 422-before-404 ordering, which was then fixed.
- Suites run: fch-api full suite: 2286 passed, 42 skipped, 0 failed; real `storage/app` unchanged.

## Open / follow-up
- **Old appointments are not cleaned up.** Appointments already booked with an off-list attendee stay, and nothing removes them. Editing one now needs a bookable attendee unless the attendee is left unchanged.
- **Odd scoping for non-host staff.** The list for non-host staff (`where partner … entity Partner ->orWhere(assigned borrowers)`) relies on an ungrouped `orWhere`. It is carried over unchanged. In the validation subquery it is self-contained, so it scopes the same as the list does.
