# Unused 2FA middleware removed (F115, was O37)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F115 (was O37)

## Problem
Two 2FA middleware were registered as aliases in `bootstrap/app.php` but used by no route:
- **`require-2fa` (`RequireTwoFactor`)** refused anyone without 2FA enabled. No portal can enable 2FA, so on any route it would have refused practically everyone.
- **`check-2fa-status` (`CheckTwoFactorStatus`)** refused a 2FA user unless `session('2fa_verified')` was set. The API authenticates with Passport tokens and has no session, so the flag is never set. On any route it would have refused every 2FA user, even after verifying.

Nothing used them, so nothing was broken. The risk was someone wiring one onto a route on the strength of its name.

## Solution
**Decision (user):** 2FA is not live, so remove them, without breaking anything.
- Deleted `app/Http/Middleware/RequireTwoFactor.php` and `app/Http/Middleware/CheckTwoFactorStatus.php`.
- Removed both aliases from `bootstrap/app.php`.
- Nothing else referred to them, which was checked across `app/`, `routes/`, `bootstrap/`, `config/` and `tests/`.
- The `User` 2FA helpers (`hasTwoFactorEnabled()` and so on) and the 2FA routes and services are untouched.

## Files changed
- `app/Http/Middleware/RequireTwoFactor.php` (deleted)
- `app/Http/Middleware/CheckTwoFactorStatus.php` (deleted)
- `bootstrap/app.php`
- `tests/Feature/General/DeadRoutesTest.php`

## Tests
- `Feature/General/DeadRoutesTest::the_unused_2fa_middleware_is_gone` checks that:
  - both aliases and both classes are gone;
  - the aliases routes do use (`partner`, `borrower`, `admin`, `partner-portal`, `host-only`, `permission`) are still registered.
- It failed first, then passed.
- Suites run: fch-api full suite: 2293 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- **Run `composer dump-autoload` after pulling**, because two classes were deleted.
- **If 2FA is ever turned on,** enforcement has to be rebuilt on the token, together with D3 (`/auth/login/2fa/confirm` issues a token without the password step) and O1 (recovery codes not shown).
- **Possibly unused lang keys.** `auth.2fa_required` and `auth.2fa_setup_required` were used only by the deleted middleware and are left in `lang/en/auth.php`.
