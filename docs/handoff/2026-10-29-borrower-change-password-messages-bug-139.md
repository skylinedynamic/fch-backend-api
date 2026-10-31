# Client portal Change Password: messages, not translation keys (BUG-139)

2026-10-29

## Problem
On the client portal's Change Password (`/change-password`), a wrong current password showed the raw key `borrower_validation.current_password_incorrect` under the field. `POST /api/v1/borrower/auth-profile/update-password` answered 422 with that key as the message. The password was correctly left unchanged.

**Cause.** `Borrower\Auth\AuthProfileService` and `UpdatePasswordRequest` use `borrower_validation.*` password keys, but none of them was in `lang/en/borrower_validation.php`. Laravel returns a missing key as-is, so the success message ("Password updated") came back as a key too.
- The admin portal uses `profile.*`, which has these messages, so it read correctly.
- Two funding-request keys (`approved_funding_string` / `approved_funding_max`) were missing as well.

**A second fault, found while testing.** `UpdatePasswordRequest` registered the Password-rule messages as `new_password.mixed` / `.numbers` / `.symbols` / `.letters`. The rule reports them as `new_password.password.<check>`, so those custom messages never applied. Laravel's generic wording showed instead.

## Solution
- **`lang/en/borrower_validation.php`:** added the missing keys.
  - The Change Password keys are worded as the admin portal's (`lang/en/profile.php`), e.g. "Current password does not match our records." and "Password updated successfully."
  - Also the two `approved_funding_*` keys.
- **`app/Http/Requests/Api/V1/Borrower/Auth/UpdatePasswordRequest.php`:** the Password-rule messages are keyed `new_password.password.<check>`.

The client portal shows the API's message as it comes, so it needs no change.

## Files changed
- `lang/en/borrower_validation.php`: the missing password and approved-funding keys.
- `app/Http/Requests/Api/V1/Borrower/Auth/UpdatePasswordRequest.php`: the message keys the Password rule actually uses.
- `tests/Feature/Borrower/BorrowerChangePasswordMessagesTest.php` (new).

## Testing
`tests/Feature/Borrower/BorrowerChangePasswordMessagesTest.php` (11 tests), all red before the fix:
- a wrong current password reads "Current password does not match our records.";
- a successful change reads "Password updated successfully.";
- each broken rule reads as its message, with no `borrower_validation.` key anywhere in the errors. The rules are: no current password, no new password, too short, one case only, no number, no symbol, confirmation differs, same as current.
- **A guard:** every `__('borrower_validation.…')` key used under `app/` exists in the lang file. It listed the 13 missing keys before the fix.

`BorrowerAccountActionsApiTest` still passes; the two files together give 15 passed.

## Open items
- `lang/en/borrower_validation.php` already had some duplicate keys elsewhere (the IDE flags them; PHP keeps the last one). They were left as they are.
