# BUG-204: partner and staff users change their own email from Profile

**Date:** 2026-11-07

## Problem
On the partner portal, Profile → Profile Details had a read-only Email field. Save said "Profile updated successfully." but ignored the email: `AuthProfileService::updateProfile()` saved only the name and phone. No mail was sent and no pending notice appeared. The read-only field has been there since the page was built (30-Apr), so there was never a way to change your own email there.

## Solution
Option (a), chosen 07-Nov: the same process as the borrower portal and the Users, Employees and Partners screens (`EmailChangeService`).
- **Save a new email:** `updateProfile()` takes the email out with `takeFrom()`, which checks availability before anything is written. After saving the name and phone it calls `request()`:
  - the new address gets the confirmation link (confirmed with the password)
  - the old address is told and can cancel
  - after confirming, the old address can still undo it (existing flow)
- **The message says which happened:** `email_change.requested` (link sent) or `email_change.cancelled`. A plain save still says "Profile updated successfully.".
- **Save the current email while a change is pending:** it withdraws the change (`cancel()`, message `email_change.cancelled`). This is the behaviour BUG-203 asks for on the client portal.
- **Taken email:** a 422 on `email`, and nothing is written.
- **`pending_email`** is now in `Global\Auth\UserResource` (login, refresh-user), so Profile still shows the pending notice after a reload.
- **Follow-up (same day):** when the host cancelled a partner's change, the partner kept seeing it, because their open session held the old signed-in user. `EmailChangeService::request()` and `cancel()` now set `is_refresh` on the user, so on the next API call the panel reloads that user through refresh-user and Profile drops (or shows) the change. This covers the host cancelling, the old mailbox's cancel link, and a change started for the user by someone else.

## Files changed
- `app/Services/FCH/Common/AuthProfile/AuthProfileService.php`: injects `EmailChangeService`; `updateProfile()` requests or withdraws the change and returns the matching message.
- `app/Http/Resources/Global/Auth/UserResource.php`: `pending_email`.
- `app/Services/Global/Account/EmailChangeService.php`: `request()` and `cancel()` set `is_refresh`.
- `tests/Feature/EmailChange/PartnerProfileEmailChangeTest.php` (new, 9 tests):
  - the host cancelling sets `is_refresh`, and refresh-user then returns no `pending_email` and clears the flag
  - a change started for the user by someone else sets `is_refresh`
  - a new email waits, and both addresses get their mail
  - refresh-user carries `pending_email`
  - a staff user changes theirs the same way
  - the same email starts nothing
  - the current email again withdraws a pending change
  - a taken email gives 422 and writes nothing
- Admin panel: `Fch-admin-frontend-react/docs/handoff/2026-11-07-bug-204-profile-email-editable.md`.

## Testing
- **New tests:** failed first (no `pending_email`; refresh-user without it), then passed.
- **Suites:** `tests/Feature/EmailChange`, `tests/Feature/User`, `tests/Feature/Profile`, `tests/Feature/Auth` and `tests/Feature/General`: all passed (344, then 271 after the resource change).
- **Live, admin panel as partner 41's owner (user 212)**, against a second `php artisan serve` on :8001 with `MAIL_MAILER=log`, so no mail went to Mailtrap. Same code and same local DB.
  - Save with a new email: 200, toast "A confirmation link has been sent to qa204.new@example.test…". The field kept the current email and the notice showed.
  - The log has "Confirm your new sign-in email" to the new address and "Your sign-in email is being changed" to the old one.
  - After a reload the notice was still there.
  - Cancel change: `pending_email` null, email unchanged.
  - The user's phone (changed to pass validation, see below) and `is_refresh` were restored afterwards.

- **Follow-up:** the 2 new tests failed first (`is_refresh` not set), then passed. `tests/Feature/EmailChange`, `User`, `Employee`, `Partner`, `Borrower` and `Auth`: 703 passed.
- **Follow-up, live (same :8001 setup):**
  - partner 41's owner saved a new email on Profile, and the notice showed
  - Host Admin cancelled it through `POST /common/email-change/cancel`: 200, `is_refresh` true
  - the partner went to Dashboard and back: refresh-user ran, the stored user had no `pending_email`, there was no notice, and the field showed the current email
  - The data was restored afterwards.

## Open items
- **Invalid phone on save:** user 212's stored phone `(133) 439-4734` fails the US-phone rule, so Profile cannot be saved without fixing it. That is unrelated local data.
- **BUG-203 (client portal):** saving the current email there still does not withdraw a pending change. Not changed (assigned to Arafat).
- Not committed.
