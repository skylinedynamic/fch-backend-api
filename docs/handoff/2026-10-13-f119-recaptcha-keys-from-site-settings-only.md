# reCAPTCHA keys from Site Settings only (F119, was O57)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F119 (was O57)

## Problem
- **Committed secret.** `.env.example`, which is tracked in git, carried a real `RECAPTCHA_SECRET_KEY`. It was the same value as the real `.env`, not Google's public test key.
- **Two places for the same key.** The host sets the reCAPTCHA keys in CMS → Site Settings, but both apps also fell back to env:
  - **fch-api:** `ReCaptcha::secret()` fell back to `config('captcha.secret')`, which comes from `RECAPTCHA_SECRET_KEY`.
  - **Website:** fch-website-react fell back to `NEXT_PUBLIC_RECAPTCHA_SITE_KEY`.

  So a stale or leaked env key could silently take over whenever Site Settings was empty.

## Solution
**Decision (user):** no env. Use Site Settings only, and with no key there, show no reCAPTCHA.

**fch-api**
- `ReCaptcha::secret()` reads only Site Settings (`recaptcha_secret_key`, module website, the host). Without it the check is off: leads are taken without a token, as F112 already did for "no secret".
- Deleted `config/captcha.php`. Nothing else read it, and `captcha.sitekey` was never used.
- Removed both `RECAPTCHA_*` lines and their comment from `.env.example`.

**fch-website-react**
- Also covered by its own handoff, `fch-website-react/docs/handoff/2026-10-13-recaptcha-from-site-settings-only.md`.
- `ReCaptchaProvider` uses only the Site Settings `recaptcha_site_key`, with no `NEXT_PUBLIC_RECAPTCHA_SITE_KEY` fallback. It also exposes `useReCaptchaEnabled()`.
- The contact page sends a token only when reCAPTCHA is on. With no key it submits without one instead of failing with "ReCaptcha not ready", and the "protected by reCAPTCHA" disclosure is hidden.

## Files changed
- `app/Rules/ReCaptcha.php`
- `config/captcha.php` (deleted)
- `.env.example`
- `tests/Feature/Website/PublicWebsiteApiTest.php`

## Tests
- `Feature/Website/PublicWebsiteApiTest::an_env_secret_is_not_used` replaces `the_env_secret_is_the_fallback`. It checks that, with an env/config secret and nothing in Site Settings, Google is not called, the lead is taken, and `config/captcha.php` is gone. It failed first, then passed.
- The other F112 reCAPTCHA tests still pass. The `setUp` line that nulled `captcha.secret` was removed because nothing reads it now.
- Suites run: fch-api full suite 2304 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
These need someone outside the code:
1. **Rotate the key pair** in the Google reCAPTCHA admin console. This needs the Google account's owner. The old secret stays in git history whatever the file says now.
2. **Put the new site key and secret** in CMS → Site Settings.
3. **Delete the old values** from the server `.env` files (`RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`). Nothing reads them now, but they are still the leaked key.

Until steps 1–2 are done, Site Settings decides: with keys there, the contact form is checked; without them, there is no reCAPTCHA at all.
