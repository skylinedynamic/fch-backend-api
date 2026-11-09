# BUG-067: login checks the password before saying anything about the account

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-067 (R2-CP-SEC-05 / R2-CP-AUTH-01): the login told apart "email does not exist" and "password incorrect", so anyone could check whether an email is registered.
- **Already fixed by 500e7f18 (27 Oct, on stage).** An unknown email, an email from another portal and a wrong password all answer "Invalid email or password."
- **Still open after that.** `AuthService::login()` checked the password **last**, after every check on the account's state. So a registered email with **any wrong password** still got a specific answer, which confirmed the email exists:
  - "Your account has been suspended" / "is currently inactive";
  - "Your free plan has expired. Please contact {partner}", which also gave away the partner's name;
  - "payment not completed", "subscription expired" or "no partner".

## Solution
`app/Services/Global/Auth/AuthService.php`:
- Right after the user is found, the password is checked (`Hash::check`) against **that** user.
- A wrong password answers like an unknown email: `auth.invalid_login` on the `password` field, counted by the same rate limiter, with `remaining_attempts`.
- The account-state messages (partner missing, portal, seat payment or subscription, free plan, user or entity status) now come only after a correct password. The owner is still told why they cannot sign in.
- The final `Auth::attempt()` is unchanged, so the rest of sign-in (2FA, token) works as before.
- This one service serves the admin, partner and borrower logins, so all portals are covered.

## Files changed
- `app/Services/Global/Auth/AuthService.php`: the password check moved ahead of the account checks.

## Testing
- **New:** `tests/Feature/Auth/BorrowerPortalLoginTest.php::a_wrong_password_never_reveals_the_accounts_state`, with 4 cases: suspended, inactive, free plan expired, and login deactivated.
  - With a wrong password: the neutral message on the password field.
  - With the right password: the account's own message.
  - All 4 failed first.
- **Run:**
  - `tests/Feature/Auth` + `tests/Feature/TwoFaAuth`: 125 passed.
  - The other test files that use login (EmailChangePortalLinks, SecurityHeaders, TenantDomainValidation, RecordMailRender, Helpers): all passed.
  - The full suite was not run.

## Open items
- **Forgot password (BUG-068)** still says "We can't find a user with that email address" for an unknown email. It is not fixed here.
