# BUG-068: forgot password gives every email the same answer

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-068 (R2-OP-AUTH-10): `POST /api/v1/auth/forgot` answered differently depending on the email, so anyone could check which emails have an account, and on which portal:

| Email | Old answer |
|---|---|
| No account | 400 "We can't find a user with that email address." |
| An account on this portal | 200 "We have emailed your password reset link." |
| An account on another portal | 403 "Invalid email or password." |
| An account, asked again too soon | 400 "Please wait before retrying." |

## Solution
`AuthController::forgot()` now always answers **200** with `auth.password_reset_link_maybe_sent`: "If an account exists for this email, a password reset link has been sent."
- The reset link is still sent only to a user who exists and can sign in on this portal, through `Password::sendResetLink`.
- Its result (sent, throttled, no user) is no longer passed on.
- **Frontends:** no change needed. The admin and client forgot password pages already show the server's message on success.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/Auth/AuthController.php`: `forgot()`.
- `lang/en/auth.php`: `password_reset_link_maybe_sent`.

## Testing
- **`tests/Feature/Auth/PasswordResetAndLogoutTest.php`:**
  - **Rewritten:** the old "unknown email fails with 400" and "another portal is refused with 403" tests asserted the leak itself.
  - **New:** "asking again too soon gets the same answer".
  - Now: four cases (known, unknown, another portal, asked again) all get the same 200 message. A token is issued only for the known user.
  - All four failed first.
- **Run:** `tests/Feature/Auth` + `tests/Feature/TwoFaAuth`, 126 passed.

## Open items
- The admin frontend's `PasswordRecovery.test.tsx` and `e2e/auth/auth.spec.ts` mock `/auth/forgot` with their own messages and still pass. They don't need the new text, but could use it.
