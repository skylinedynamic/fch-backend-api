# Login messages: one answer for wrong email or password, inactive partner sent to administrator (BUG-104, BUG-113)

Date: 2026-10-27

## Problem
- **BUG-104:** a sign-in with an unknown email answered "The email you entered does not exist". A known email with a wrong password answered "The password you entered is incorrect". Anyone could use the login form to check which emails have an account. The lockout message also said "after 1 minutes".
- **BUG-113:** an inactive partner's owner was told "Your account is currently inactive. Please contact QA-TEST Run1 Partner Lead Co for activation.", naming their own company. The suspended message did the same.

## Solution
- An unknown email, a wrong password and a user on the wrong portal now all answer `auth.invalid_login` ("Invalid email or password.") with `field: password`. The attempt counters are unchanged. The old `invalid_email` and `invalid_password` keys are removed, and `PortalIsolationService::getUnauthorizedMessage()` uses the new key.
- `too_many_login_attempts` is a plural string read with `trans_choice`, so it says "1 minute" or "5 minutes". `RateLimitServiceProvider` passes the time, which it never did before.
- `partner_inactive` and `partner_suspended` say "Please contact administrator", since the host is who sets a partner's status. The `:partner` placeholder is gone.

## Files changed
- `app/Services/Global/Auth/AuthService.php`: generic message and field; partner status messages without the partner name; lockout uses `trans_choice`.
- `app/Services/Global/Common/PortalIsolationService.php`: `auth.invalid_login`.
- `app/Providers/RateLimitServiceProvider.php`: lockout message with its time, pluralised.
- `lang/en/auth.php`: `invalid_login` added; `invalid_email` and `invalid_password` removed; plural `too_many_login_attempts`; administrator wording for the two partner messages.
- `tests/Feature/Auth/PartnerPortalLoginTest.php`: unknown email and wrong password cannot be told apart; "1 minute"; inactive and suspended partner messages.
- `tests/Feature/Auth/BorrowerPortalLoginTest.php`: expects `auth.invalid_login`.

## Testing
- The new tests failed first, then passed. `tests/Feature/Auth`: 96/96.
- Full suite: 3809 tests, 1 failure. The failure is the existing `NavigationMapTest` capital_access case and is not related.

## Open items
- An inactive or suspended account still gets its status message before the password is checked, so that path can still confirm an email exists. Left as is so those users see why they cannot sign in.
- The client and admin login E2E specs now expect "Invalid email or password." (see their handoffs).
