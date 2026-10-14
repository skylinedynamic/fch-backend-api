# Login reCAPTCHA removed (F121, was D6)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F121 (was D6)

## Problem
`LoginRequest`, used for both partner and borrower login, declared `'recaptcha' => ['nullable', new ReCaptcha()]`. Neither portal sends a token, so the check never ran and stopped no scripted client. The only real protection is the per-email/IP login throttle.

The leftover rule could still bite. Since F112/F119 the rule checks against the website's Site Settings secret. A login request that carried any `recaptcha` value would be checked against that secret, and a valid login refused (422).

## Solution
**Decision (user):** partner and borrower login have no reCAPTCHA, so remove it from login.
- Removed the `recaptcha` rule and its message from `LoginRequest`, along with the `ReCaptcha` import.
- `AuthService::login()` builds the credentials it passes to `Auth::attempt()` from `email` and `password` only (`only([...])`), instead of excluding `recaptcha`. Since `validated()` holds only those two fields, the behaviour is the same.
- The `ReCaptcha` rule class stays: the website contact form uses it (F112). `BorrowerRegistrationRequest` was already removed with self sign-up (F118).

## Files changed
- `app/Http/Requests/Api/V1/Global/Auth/LoginRequest.php`
- `app/Services/Global/Auth/AuthService.php`
- `tests/Feature/Auth/PartnerPortalLoginTest.php`

## Tests
- `Feature/Auth/PartnerPortalLoginTest::login_has_no_recaptcha_check`. The setup sets a Site Settings secret and has Google set to refuse. A valid login that sends a stray `recaptcha` value still signs in, and Google is not called. This failed first with 422, then passed.
- All auth and website tests pass (118).
- Suites run: fch-api full suite 2310 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
None. Login is still protected by the per-email/IP throttle (`LOGIN_THROTTLE_LIMIT`, `LOGIN_DECAY_MINUTES`).
