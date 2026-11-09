# Public endpoint for the website's /apply form

**Date:** 2026-11-07

## Problem
The marketing site's new `/apply` page embeds the host's application form. Its link (`/form/share_pub_…`) was hard-coded in the website, so every time the host regenerated its key in Embedded Form settings, the website had to be changed and redeployed. The website had no way to ask the API for that link.

## Solution
New public route `GET /api/v1/website/embed-form`. It returns the host partner's form as `{ form_title, form_url }` and nothing else.
- The URL is built by `EmbedFormLocation::forPartner($host)->formUrl()`, the same rule the settings screen uses:
  - `https://<BASE_WEBSITE>/form/<key>` outside local
  - `BASE_API_URL` in local
- It returns 404 `embed_form.form_not_found_or_inactive` when:
  - there is no host
  - the host has no form, or its form is inactive
  - the host has no address to publish on
- It never creates a form, because anonymous visitors call it.
- No `allowed_domains`, no notification email and no partner ids are exposed.

## Files changed
- `routes/website.php`: `Route::get('embed-form', [PublicEmbedFormController::class, 'hostForm'])` in the `v1/website` group.
- `app/Http/Controllers/Api/V1/Global/Website/EmbedForm/PublicEmbedFormController.php`: `hostForm()`.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `hostFormDetails()`.
- `tests/Feature/EmbedForm/WebsiteApplyFormTest.php`: new, 4 tests.

## Testing
- **New test file:** `WebsiteApplyFormTest` failed before the change (404, no route) and passes after. Its 4 tests check:
  - the host's title and link are returned, not another partner's
  - only those two keys are returned
  - an inactive form gives 404
  - the call creates no form
- **Suite:** `tests/Feature/EmbedForm` passes, 147 tests.
- **Live, local DB:** `curl http://127.0.0.1:8000/api/v1/website/embed-form` returned `form_url = http://127.0.0.1:8000/form/share_pub_5b0d5728…`. The website's `/apply` page put that link into its iframe and the form loaded inside it.

## Open items
- The route has no throttle, like the other `v1/website` reads.
- The form's `allowed_domains` must include the website's domain, or the browser blocks the iframe (`frame-ancestors`). Production already lists `fourcornerfunding.com` and `www.fourcornerfunding.com`.
- Not committed.
