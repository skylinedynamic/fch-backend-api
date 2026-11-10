# BUG-200: admin password change email says the password was changed

**Date:** 2026-11-07

## Problem
Users → a user → "Change Account Password" → Update. The user got an email whose subject was "Your Password has been updated", but whose body was the new-account welcome: "Welcome to … Your account has been successfully created … Temporary Password: <password> … Please login and change your password". The bell also said "Welcome aboard – Your account is set up".

`UserService::update()` reused `sendCreatedMail()` (`emails.users_created`, notification type `user.account.created`) and only swapped the subject.

Decision (07-Nov): the new password stays in the email. Only the wording and the bell are fixed.

## Solution
- **New method:** `UserService::sendPasswordChangedMail()` is used when the Users screen changes a password.
  - **Bell:** `user.account.login_updated`, "Your sign-in details were changed".
  - **Mail:** `UserCreateNotificationMail` with `is_password_change`.
- **Template:** with that flag, `emails.users_created` reads:
  - **intro:** "The password for your account was changed by an administrator. Below are your new login details:"
  - **card:** "New Login Details"
  - **label:** "New Password:", followed by the password
  - **notice:** "If you did not expect this change, contact your administrator. Please login and change your password for security."
- **Lang:** the strings are in `lang/en/user.php` (`password_changed_mail.*`).
- **Unchanged:** the subject stays `common.password_updated_subject`. New-user creation still sends the unchanged welcome with a temporary password.

## Files changed
- `app/Services/FCH/Common/User/UserService.php`: `sendPasswordChangedMail()`, used by `update()`.
- `app/Services/Global/Notification/Mail/UserCreateNotificationMail.php`: passes `isPasswordChange` to the view.
- `resources/views/emails/users_created.blade.php`: password-change wording behind the flag.
- `lang/en/user.php`: `password_changed_mail` strings.
- `tests/Feature/User/AdminPasswordChangeMailTest.php` (new, 3 tests):
  - one email with the new password and the "changed by an administrator" wording, with no "successfully created", "Temporary Password" or "Welcome to"
  - bell `user.account.login_updated`, not `user.account.created`
  - a new user still gets the welcome with a temporary password

## Testing
- **New tests:** the wording test and the bell test failed first, then passed. The new-user guard passed before and after.
- **Suites:** `tests/Feature/User`, `tests/Feature/Employee`, `tests/Integration/Mail`, `tests/Unit/Mail`, `tests/Feature/Auth` and `tests/Feature/Notification`: 430 passed.
- **Local MySQL through the real `PUT /api/v1/common/users/102`** as Host Admin, with mail on the `array` transport and inside a rolled-back transaction:
  - HTTP 200, one email to the user
  - subject "Your Password has been updated"
  - body has "changed by an administrator", "New Password" and the new password, and none of "successfully created", "Temporary Password" or "Welcome to"
  - bell `user.account.login_updated`
  - the password hash was unchanged afterwards

## Open items
- `sendCreatedMail()`'s `$subject` parameter is no longer passed by any caller. Left as is.
- Not committed.
