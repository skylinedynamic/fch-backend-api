# BUG-203: one pending-email rule for every form

**Date:** 2026-11-11
**Branch:** round-1-run-2-fix
**Author:** Awlad Hossain

## Problem
BUG-203: on the client (borrower) portal, typing your current email back into Profile and saving did not withdraw a pending email change. The rule also differed between screens. Partner/staff Profile (BUG-204) withdrew the change, but it did so whenever the current email was resubmitted. Every save resubmits the field, so editing only the name would also withdraw it. The borrower Profile and the admin Clients, Partners, Employees and Users forms kept the change in every case.

## Solution
One rule, applied in `EmailChangeService` and used by all seven save paths:
- While a change waits, **every form shows the pending email in the field**. The portals and react-fch were changed to match.
- `takeFrom()` now compares the submitted email with `pending_email ?? shownEmail`, so:
  - **Pending email sent back unchanged:** nothing happens and the change stays. This is a save that only edits other fields.
  - **Current email typed back in:** returned, and `request()` withdraws the change. It already treated the current email as a cancel.
  - **Another email:** a new request replaces the old one.
- New `withdraws($user, $email)` tells the caller which of these happened, so the message is right: `email_change.cancelled` or `email_change.requested`. It must be called before `request()`.
- `AuthProfileService::updateProfile()` no longer has its own `$withdraw` logic.
- `ProfileService::updateProfile()` (borrower) now returns the message string. `PersonalInfoController` passes it through.
- Admin services (Borrower, Partner, Employee, User) needed no code change. They pick up the rule through `takeFrom()`.

## Files changed
- `app/Services/Global/Account/EmailChangeService.php`: `takeFrom()` compares against the pending email, new `withdraws()`, updated doc.
- `app/Services/FCH/Common/AuthProfile/AuthProfileService.php`: uses the shared rule; withdraw message from `withdraws()`.
- `app/Services/Borrower/Profile/ProfileService.php`: withdraws on the current email; returns the message.
- `app/Http/Controllers/Api/V1/Borrower/Profile/PersonalInfoController.php`: uses the returned message.
- `tests/Feature/EmailChange/BorrowerProfileEmailChangeTest.php`:
  - `saving_the_shown_email_again_is_not_a_change` replaced by four tests: current email with nothing pending; pending email resaved (kept); current email (withdrawn); another email (replaced).
- `tests/Feature/EmailChange/PartnerProfileEmailChangeTest.php`: `resaving_the_pending_email_keeps_the_change`.
- `tests/Feature/EmailChange/EmailChangeThroughEditFormsTest.php`:
  - `saving_a_user_without_touching_the_email_keeps_a_pending_change` now resubmits the pending email.
  - New withdraw tests for Users, Employees, Partners and Clients.

## Tests
- The 7 new and changed tests failed first, then passed.
- `tests/Feature/EmailChange`: 92 passed.
- `tests/Feature/{User,Employee,Partner,Borrower,Auth,Profile,General}`: 786 passed.

## Open / follow-up
- **Deploy fch-api, borrower-fch and react-fch together.** An old frontend fills the field with the current email, and under the new rule that withdraws a pending change on any save.
- Portal sides: `borrower-fch/docs/handoff/2026-11-11-bug-203-pending-email-same-rule-everywhere.md` and `react-fch/docs/handoff/2026-11-11-bug-203-pending-email-same-rule-everywhere.md`.
- BUG-203 was assigned to Arafat. Let them know it is done here.
- Not committed.
