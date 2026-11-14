# reCAPTCHA: off unless both keys are saved, and the secret can be removed

**Date:** 2026-11-10

## Problem
- **Half set up blocked every website lead.** The website makes a reCAPTCHA token only when Site Settings has a site key. The API demanded one as soon as a secret was saved. With a secret but no site key (seen locally), both website forms got "Please complete the reCAPTCHA verification." on every submit.
- **The saved secret could not be removed.** In CMS → Site Settings a blank secret means "keep" (the form never holds the secret, BUG-022). Clearing the field and saving kept the old secret, so there was no way out of the state above from the admin panel.

## Solution
- `ReCaptcha::isConfigured()`: true only when Site Settings holds **both** the secret and the site key. `Global/Website/Lead/StoreLeadRequest` requires the token only then, and the rule (with `skipWhenUnconfigured`) skips the check otherwise. With one key only, leads are taken without a check and a warning is logged.
- `UpdateSiteSettingsRequest`: new optional boolean `remove_recaptcha_secret_key`. `SiteSettingsService::updateSettings()` empties the stored secret when it is true. The flag wins over a secret sent with it and is never stored as a setting.
- A blank secret or the mask still means "keep", so an older admin page, which sends a blank secret on every save, can never delete it. Safe in any deploy order.
- Admin side (react-fch): the field now shows the mask (dots) when a secret is saved. Left alone it is kept; clearing it sends `remove_recaptcha_secret_key`. See `react-fch/docs/handoff/2026-11-10-site-settings-recaptcha-secret-display.md`.

## Files changed
- `app/Rules/ReCaptcha.php`: `isConfigured()`; the skip uses it.
- `app/Http/Requests/Api/V1/Global/Website/Lead/StoreLeadRequest.php`: token required only when `isConfigured()`.
- `app/Http/Requests/Api/V1/FCH/Admin/CMS/UpdateSiteSettingsRequest.php`: `remove_recaptcha_secret_key` rule.
- `app/Services/FCH/Admin/CMS/SiteSettingsService.php`: `REMOVE_RECAPTCHA_SECRET` constant and the removal.
- `tests/Unit/Rules/ReCaptchaConfiguredTest.php`: new.
- `tests/Feature/Website/PublicWebsiteApiTest.php`: the "set up" helper now saves both keys; new half-set-up test.
- `tests/Feature/Website/CmsSeoAndSiteSettingsAdminApiTest.php`: 2 new removal tests.

## Testing
- 7 new tests written first; all 7 failed before the change and pass now.
- `tests/Unit/Rules`, `tests/Feature/Website`, `tests/Unit/Setting`: 127 passed.
- `tests/Feature/Auth`, `tests/Feature/Lead`, `tests/Unit/Lead`, `tests/Integration/PreQualification`: 286 passed.

## Open items
- Local Site Settings still hold a 9-character test secret and no site key. With this change leads go through. Remove the secret from Site Settings, or save a real v3 key pair (40 characters, starting `6L`).
- MicroBilt and Credit Profile secrets keep the old "blank keeps it, no way to remove" behaviour. Not changed here.
