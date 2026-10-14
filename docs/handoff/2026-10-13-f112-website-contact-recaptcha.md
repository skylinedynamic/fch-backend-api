# Website contact form reCAPTCHA (F112, was O29, part of D5)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F112 (was O29, part of D5)

## Problem
Only one form in the system actually uses reCAPTCHA: the public website's **contact page** (`fch-website-react/src/app/contact/page.tsx`). It runs reCAPTCHA v3 with the action `contact_form` and sends `recaptcha_token` to `POST /api/v1/website/leads`. The token did nothing:
- **The token was never checked.** The website `StoreLeadRequest` had no rule for `recaptcha_token`, so any script could post leads. Each lead also emails the host.
- **The secret saved in the UI was never used (O29).** `App\Rules\ReCaptcha` read the secret as `AppConfiguration::get('recaptcha_secret_key', null, Partner::class, 1)`, that is module `Partner` and partner id 1. Site Settings saves it as module `website` on the host's row. So the rule always fell back to `RECAPTCHA_SECRET_KEY`.
- **v3 checks were missing.** The rule only checked `success`. A v3 token always reports success, and the real signal is `score` and `action`.

## Solution
**Decisions (user):**
- Repair reCAPTCHA only where it already exists.
- No new forms get it: pre-qualification and embed forms stay without a CAPTCHA.
- Partner and borrower login need none (D6).
- No rate limit was added.

**`App\Rules\ReCaptcha`**
- `ReCaptcha::secret()` reads Site Settings first (`AppConfiguration::platform('recaptcha_secret_key', MODULE_WEBSITE)`, the host's row or an unowned one), then `config('captcha.secret')`.
- New optional arguments:
  - `action` and `minScore` (default 0.5): when an action is given, Google's `action` must match and `score` must reach the minimum.
  - `skipWhenUnconfigured`: with no secret anywhere, let the value through and log a warning instead of failing.
- A missing or unparsable Google response now fails cleanly instead of erroring on `$result['success']`.

**Website `StoreLeadRequest`**
- `recaptcha_token` is required once a secret is configured.
- It is verified as `new ReCaptcha('contact_form', skipWhenUnconfigured: true)`.
- With no secret configured, leads are taken as before, so the contact form does not break before reCAPTCHA is set up.

**Login (`LoginRequest`)** is unchanged. Its token stays optional and the portals send none. It now reads the same secret.

## Files changed
- `app/Rules/ReCaptcha.php`
- `app/Http/Requests/Api/V1/Global/Website/Lead/StoreLeadRequest.php`

## Tests
- `Feature/Website/PublicWebsiteApiTest`, with Google mocked via `Http::fake`:
  - `a_contact_form_lead_is_verified_with_the_site_settings_secret`: Google receives the Site Settings secret and the browser token.
  - `a_contact_form_lead_without_a_good_token_is_refused`: 422 for a missing token, `success: false`, a low score or the wrong action. No lead is stored and no mail is sent.
  - `the_env_secret_is_the_fallback`
  - `with_no_secret_configured_leads_are_taken_without_a_check`: Google is not called.
- The first three failed first; the "no secret" case already passed.
- `setUp` now sets `captcha.secret` to null, so results never depend on the machine's `.env`. The local `.env` does set a secret.
- Suites run: fch-api full suite: 2290 passed, 42 skipped, 0 failed; real `storage/app` unchanged.

## Open / follow-up
- **O57, security:** `.env.example`, which is tracked in git, contains the same `RECAPTCHA_SECRET_KEY` as the real `.env`, not Google's test key.
  - Rotate the key pair in the reCAPTCHA admin console.
  - Put the new secret in Site Settings or each environment, and the new site key on the website.
  - Leave the example value empty. Deleting it from the file does not remove it from git history.
- **Key pair must match.** Once a secret is configured, the website's site key (Site Settings `recaptcha_site_key` or `NEXT_PUBLIC_RECAPTCHA_SITE_KEY`) must be from the same key pair. Otherwise every contact-form submission is refused.
- **Score threshold.** 0.5 is Google's suggested default. Raise or lower it in `StoreLeadRequest` if real visitors get refused or spam gets through.
