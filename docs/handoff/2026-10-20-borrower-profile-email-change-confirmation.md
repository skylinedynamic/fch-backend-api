# Borrower profile email change goes through confirmation

**Date:** 2026-10-20
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
`POST /api/v1/borrower/profile/personal-info` (`ProfileService::updateProfile`) changed a borrower's own email straight away in both `users.email` and `borrowers.email`. It also reset the password to a random one and mailed it to the new address (`BorrowerAccountCreatedMail`). A typo locked the borrower out, and whoever owned the typed address received a working password. It was the only email-change path that skipped `EmailChangeService`.

## Solution
- `ProfileService::updateProfile` now calls `EmailChangeService::takeFrom()` before writing anything:
  - A taken email (another active login, compared ignoring capitals) is a 422 on `email`, and nothing is saved.
  - Otherwise the email is taken out of the save. After the commit, `request()` holds it as `pending_email` and mails the confirmation link to the new address and a notice to the old one.
  - It returns the requested email (or null). The password is kept and the password mail is gone.
- `PersonalInfoController::update` re-throws the `ValidationException` (it used to become a 500). When a change was requested, the message is `email_change.requested`.
- `PersonalProfileResource` adds `pending_email`.
- New borrower-only endpoints act on the signed-in login only, with no `user_id` to tamper with:
  - `POST /api/v1/borrower/profile/email-change/resend` (throttled 6/min)
  - `POST /api/v1/borrower/profile/email-change/cancel`
  - Controller: `App\Http\Controllers\Api\V1\Borrower\Profile\EmailChangeController`.
  - The existing `common/email-change/*` routes stay partner-portal only.
- Confirming uses the existing links: the borrower portal's `/email-change/confirm/...` page and `PortalUrlResolver`.

## Files changed
- `app/Services/Borrower/Profile/ProfileService.php`: goes through `EmailChangeService`. The direct email write, the password reset and `BorrowerAccountCreatedMail` are removed.
- `app/Http/Controllers/Api/V1/Borrower/Profile/PersonalInfoController.php`: the "requested" message, and the 422 is passed through.
- `app/Http/Controllers/Api/V1/Borrower/Profile/EmailChangeController.php` (new): resend and cancel for the own login.
- `app/Http/Resources/Borrower/Profile/PersonalProfileResource.php`: `pending_email`.
- `routes/borrower.php`: the two routes.

## Tests
- Added `tests/Feature/EmailChange/BorrowerProfileEmailChangeTest.php` (13 tests):
  - the change is held as pending and mails go to both addresses;
  - the password is kept and no password mail is sent;
  - the rest of the profile is still saved;
  - the GET shows `pending_email`;
  - resubmitting the shown email (any capitals) is not a change;
  - a taken email is a 422 and nothing is saved;
  - an email held only by a deleted login is allowed;
  - resend, resend with nothing pending, and cancel all work;
  - a `user_id` in the body is ignored;
  - a partner login gets 403.
- Run:
  - this test plus `tests/Feature/Profile`: 42 passed;
  - full suite (`php artisan test`): 3206 passed, 2 skipped.

## Open / follow-up
- Two logins can have the same pending email at once. The first to confirm gets it, and the other gets "Taken" when they confirm. This is how every email change works.
