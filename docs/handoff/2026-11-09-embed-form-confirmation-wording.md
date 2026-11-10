# Embed form: confirmation without a response-time promise

**Date:** 2026-11-09
**Branch:** bugfix/iframe-application-website
**Author:** Awlad Hossain

## Problem
SCRUM-435 S435-BUG-02: after submitting, the form said "Our team has received your application and will contact you shortly". The brief (section 7) says the confirmation must explain the next step and not promise approval, funding or an unverified response time. "Shortly" is a response-time promise.

## Solution
The confirmation now reads:

> Thank you for applying with **{company name}**. Our team has received your application and will review it. If we need anything else or have an update, we'll contact you using the details you provided. Submitting an application does not guarantee approval or funding.

**Which company name is shown (changed, Awlad's decision):** `$partnerName` used to take the host's `site_name` when a partner had none of its own, so that partner's applicants saw "Four Corner Funding" and never reached `company_name`. The order is now:

1. The partner's own `site_name` in `app_configurations` (new `AppConfigurationService::ownSiteName()`)
2. `partners.company_name`
3. `full_name`, then `config('app.name')`, as before

`getBrandingSettings()` itself is unchanged, because other surfaces rely on its host fallback.

**Behaviour change:** `$partnerName` is one variable, so this applies everywhere on the form: the page title, "Powered by", the footer, the confirmation, and the step 6 and step 8 authorization text. A partner with no `site_name` now shows its own company name in all of them.

The new text appears on every form, including those embedded on partner sites.

## Files changed
- `resources/views/embed-form/index.blade.php`: the success screen's text.
- `app/Services/FCH/Common/Setting/AppConfigurationService.php`: new `ownSiteName()`.
- `app/Http/Controllers/Web/EmbedFormPageController.php`: the new name order.
- `tests/Feature/EmbedForm/EmbedFormSuccessMessageTest.php`: new, see below.
- `tests/Integration/Branding/OwnSiteNameTest.php`: new, see below.
- `fch-website-react/e2e/apply-tracking.spec.ts`: the confirmed-submission spec also checks this text.

## Tests
- Added: `EmbedFormSuccessMessageTest`, 9 tests:
  - No response-time wording, and the next step and no-guarantee sentence are present. Both failed before the wording change.
  - A partner with no `site_name` shows its company name rather than the host's, on the confirmation and in the authorizations. Both failed before the name change.
  - The host shows its own `site_name`. This also failed first, most likely because of the branding service's per-process memo between tests rather than a product bug; I did not confirm that.
  - Own `site_name` beats company name, the name differs per partner and is escaped. These passed before too.
- Added: `OwnSiteNameTest`, 6 integration cases: own value, no host fallback, other modules ignored, and blank values. All failed before the method existed.
- Updated: the website's Playwright spec for a confirmed submission failed before the change and passes after it.
- Suites run: `tests/Feature/EmbedForm`, `tests/Integration/Branding` and `tests/Unit/Branding` together, 269 passed; website Playwright, 5 passed (Chromium, with the submit response mocked). The whole API suite was not run.

## Open / follow-up
- **Wording sign-off:** this text is a proposal from the developer. FCH or compliance has not approved it. If they send other wording, change the Blade text and `EmbedFormSuccessMessageTest`.
- **Authorization text:** the company named in the consents changes for partners with no `site_name`. It now names the partner the form belongs to, but compliance has not reviewed this.
- **Other surfaces:** mail and other screens still use the old host fallback. They were not changed.
- **BUG-06 is separate:** the Reference ID is still cut off on phones.
- Not committed.
