# Embed code options for partners, and the /apply page's code from CMS settings

**Date:** 2026-11-08

## Problem
- **Partners could not change the embed code.** Embed Form Settings handed out one fixed iframe: always 800px tall, always with the form's own logo header, copyright, grey page and card. A partner could not fit it to their own site. The direct link was fine and stays as it is.
- **The website did not use the same code.** The user wants our own `/apply` page to show the form exactly as a partner would: copy the code from Embed Form Settings and paste it in. Until now the website built its own `?embed=1` link.
- **A gap found while testing:** in an iframe, the form only asked the page around it to scroll and never scrolled itself. With a fixed height and nothing listening on the partner's page, an error from Continue stayed out of view.

## Solution
- **Display options in the form's link** (`App\Support\EmbedForm\EmbedFormDisplay`):

  | Param | Values | Default | Effect |
  |---|---|---|---|
  | `header` | `0`/`1` | `1` | Logo, title and "Powered by" card |
  | `footer` | `0`/`1` | `1` | The copyright line |
  | `bg` | `grey`/`white`/`transparent` | `grey` | The page background |
  | `frame` | `0`/`1` | `1` | The card's border, shadow and padding |

  - Only listed values are read; anything else falls back to the default and never reaches the markup.
  - `embed=1` is kept as a preset meaning `header=0&footer=0&bg=transparent&frame=0`. An explicit option overrides it.
  - The view gets `$display` instead of `$embedded`, and `<body>` gets the classes `form-bg-*` and `is-frameless`.
- **Automatic height on any site:** new `public/form/assets/embed.js`.
  - It sizes `iframe[data-fch-embed]` from the form's existing `fch-embed-form` resize messages and follows its scroll messages.
  - It accepts messages only from that iframe and from the script's own origin.
  - It lives under `/form/` so the edge proxy that already serves `/form/*` serves it too.
  - `EmbedFormLocation::embedScriptUrl()` builds its address, and the config resource returns it as `embed_script_url`.
- **The form also scrolls itself:** `scrollFormTo()` still asks the page around it to scroll, and now always scrolls its own window as well. With automatic height there is nothing inside the iframe to scroll, so nothing changes there.
- **The website's code, pasted in CMS > Site Settings:** new setting `apply_form_embed_code` (`EmbedSnippet::SETTING_KEY`).
  - **Checked on save:** the rule `App\Rules\EmbedFormSnippet` accepts only the builder's own code. That means exactly one iframe on the host's form address and current key, at most one script that is that address's `embed.js`, no other tags, no event handlers and no inline script.
  - **Never served as HTML:** the code is not in the public settings list.
  - **`GET /website/embed-form` adds new fields:** `embed_url` (the current key's link plus the pasted options), `auto_height`, `height` and `radius`.
  - **Key changes:** the key in the pasted code is ignored, so regenerating the key does not break `/apply`.
  - **Nothing pasted** gives today's `?embed=1` with automatic height.
- `embed_snippet` and `direct_url` are unchanged.

## Files changed
- `app/Support/EmbedForm/EmbedFormDisplay.php`: new; the options, their defaults, the preset, body classes and `toQuery()`.
- `app/Support/EmbedForm/EmbedSnippet.php`, `InvalidEmbedSnippet.php`: new; parse the pasted code into its options.
- `app/Rules/EmbedFormSnippet.php`: new; checks the pasted code against the host's form.
- `app/Models/EmbedForm/EmbedFormConfig.php`: `EMBEDDED_QUERY_PARAM` now points at `EmbedFormDisplay::PARAM_EMBED`.
- `app/Http/Controllers/Web/EmbedFormPageController.php`: passes `display` to the view.
- `resources/views/embed-form/index.blade.php`: the header and footer depend on the options; new background and frameless CSS; `scrollFormTo()` also scrolls the form itself.
- `public/form/assets/embed.js`: new; the auto-height script.
- `app/Support/EmbedForm/EmbedFormLocation.php`: `embedScriptUrl()`.
- `app/Http/Resources/FCH/EmbedForm/EmbedFormConfigResource.php`, `EmbedFormController.php`: add `embed_script_url`.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `hostFormDetails()` returns the framing fields from the pasted code.
- `app/Http/Requests/Api/V1/FCH/Admin/CMS/UpdateSiteSettingsRequest.php`: validates `apply_form_embed_code`.
- `lang/en/site_settings.php`: three messages for a refused code.

## Testing
- **New tests:**
  - `tests/Feature/EmbedForm/EmbedFormDisplayOptionsTest.php` (9): each option, the preset, an override, bad values never printed, and the script path is not looked up as a form key.
  - `tests/Unit/EmbeddedForm/EmbedSnippetTest.php` (13): reading the options; the bounds; refusing other tags, handlers, `javascript:`, inline or extra scripts, text, and a non-form path.
  - `tests/Feature/Website/ApplyFormEmbedCodeTest.php` (7): saving, the endpoint's fields, automatic height, a regenerated key, no raw HTML in public output, refusals (another site, another form, a foreign script, a handler, not embed code), and clearing.
- **Updated tests:**
  - `EmbedFormEmbeddedLayoutTest`: body classes.
  - `WebsiteApplyFormTest`: the endpoint's field list now includes the framing fields.
  - `EmbedFormLocationTest`: `embedScriptUrl()`, and null with no address.
  - `EmbedFormSubmissionsApiTest`: the settings return `embed_script_url`.
  - `EmbedFormParentBridgeTest`: the form scrolls itself. The test failed before the view change and passes after it.
- **Suite:** `php artisan test tests/Feature/EmbedForm/ tests/Feature/Website/ tests/Unit/EmbeddedForm/` passes.
- **Browser:** headless Chromium against the local API, with a partner page served from `localhost:4599`:
  - **Automatic height** (`header=0&bg=transparent&frame=0`): the iframe follows the form (731px, then 841px once errors show), with no inner scroll. Continue brings the first error to 247px from the top, below the partner's 70px sticky header.
  - **Fixed 700px:** the height stays fixed, and the form scrolls inside to the error.
  - **The old code with no options:** looks unchanged, and now also scrolls to the error.
- **`/apply`:** checked with a code saved through the rule into the local DB (white background, fixed 900px, 12px radius). The row was removed afterwards and `/apply` was back to the default.

## Open items
- **Production proxy:** it must forward `/form/assets/*` as it does `/form/{key}`. If its rule matches only the key, `embed.js` will 404 on partner domains and automatic height will not work there. A fixed height still works.
- **Allowed Domains:** a partner's Allowed Domains (CSP `frame-ancestors`) must include their own site. The admin preview stays blank unless the admin's own site is listed too.
- **Button colour** is still the branding `primary_color`.
- This change replaces the single `embed` switch from `2026-11-08-embed-form-embedded-layout.md`; `embed=1` still works.
- Not committed.

## Follow-up (same day): the apply form in the public site settings
- **What changed:** `GET /api/v1/website/settings` now includes `apply_form`, from `EmbedFormService::hostFormDetails()`; it is null with no live form. The website's `/apply` reads it from there.
  - The raw `apply_form_embed_code` is still not in that response.
  - `WebsiteSiteSettingsService` gets `EmbedFormService` through its constructor.
- **Tests:** `ApplyFormEmbedCodeTest` covers the settings carrying `apply_form` (and not the raw code), and `apply_form` being null with no live form. Both failed before the change. `tests/Feature/Website`, `tests/Feature/EmbedForm` and `tests/Unit/EmbeddedForm` pass (412 tests).

## Follow-up 2 (same day): the settings send the pasted code itself
- **What changed:** `GET /api/v1/website/settings` sends `apply_form_embed_code`, the code exactly as pasted, and no longer `apply_form`. The website renders it.
  - **The check on output:** the value is sent only while it passes the same `EmbedFormSnippet` check as on save (one iframe on the host's current form, at most its `embed.js`). Otherwise it is null. That covers a value written straight to the table, and an old key after a regeneration.
  - `/website/embed-form` is unchanged.
- **Tests:** `ApplyFormEmbedCodeTest` covers:
  - the settings carrying the code as saved
  - null with nothing saved
  - a stored code that fails the check never being served
  - `/website/embed-form` still sending only the options

  The test "the public site never receives the pasted HTML" was replaced on purpose, because sending the code is now the requested behaviour. `tests/Feature/Website`, `tests/Feature/EmbedForm` and `tests/Unit/EmbeddedForm` pass (413 tests).
- **Trade-off, accepted by the user:** after a key regeneration `/apply` shows no form until the new code is pasted.

## Follow-up 3 (same day): automatic height without a referrer
- **Reported:** "I select Automatic height but iframe showing height 800" on `/apply`.
- **Cause:** the form learned whom to report its height to only from `document.referrer`. When a browser, extension or Referrer-Policy drops the referrer, it never reported, and the iframe stayed at the code's `height="800"`. Reproduced with `<meta name="referrer" content="no-referrer">`: 800px.
- **Fix:** a handshake.
  - The framing page (`embed.js`, and the website's `ApplyFormFrame`) sends `{source: 'fch-embed-form', type: 'hello'}` to the form's origin when the iframe loads.
  - The form accepts it only from its own `window.parent`, takes `event.origin` as the page to report to, and reports its height at once.
  - Framed at all, the form now always measures itself; it no longer waits for a referrer.
- **Tests:** `EmbedFormParentBridgeTest` has two new tests: the form answers the hello, and `embed.js` sends it. Both failed first. All 168 tests in `tests/Feature/EmbedForm` pass.
- **Browser, with no referrer:** `/apply` is 709px (was 800px). A partner page using `embed.js` is 731px, then 841px with errors.
- **The grey background in the same report** comes from the saved code, which has `header=0&footer=0&frame=0` but no `bg=transparent`. The code must be copied again with Transparent chosen.

## Follow-up 4 (same day): a colour code for the background, and a page with no origin
- **Colour code:** `bg` now also takes a colour code, as six hex digits without the `#` (`bg=f5f7fa`).
  - `EmbedFormDisplay::BACKGROUND_COLOUR_PATTERN` allows only that; anything else falls back to the default.
  - The body gets `form-bg-custom` and `style="--form-bg: #rrggbb"`, built only from the checked value.
  - `EmbedSnippet` reads it back from a pasted code too.
- **A page with no origin:** "Automatic height, still 800" also happened for code tried in a page opened from a file. Such a page has the origin `'null'`, which `postMessage` refuses as a target, so the form threw.
  - The form now answers a hello from a `'null'` origin with `'*'`: only layout numbers, and only to its own parent window.
  - `embed.js` (and the website) no longer say hello to the iframe's blank placeholder before the form loads, which had left a console warning.
- **Tests:**
  - `EmbedFormDisplayOptionsTest`: a colour code is applied, and only a six-digit code is read (`#1a2b3c`, `abc`, `1a2b3cff`, `zzzzzz`, `red` and `1a2b3c;color:red` all fall back to grey).
  - `EmbedSnippetTest`: a colour code is read from the code.
  - `EmbedFormParentBridgeTest`: a no-origin page still gets the height.

  All were written first and failed first. `tests/Feature/EmbedForm`, `tests/Unit/EmbeddedForm` and `tests/Feature/Website` pass (419 tests).
- **Browser:** the user's code in a `file://` page gives 709px (was 800px). `/apply` is 709px with and without a referrer, and a partner page with `embed.js` is 731px with and without one.

## Follow-up 5 (same day): the height attribute
- **Reported:** "why always showing height: 800 in iframe?"
- **Cause:** the iframe really was 709px; `style.height` was set. But the code's `height="800"` attribute never changed, so the browser's inspector kept showing 800.
- **Fix:** `embed.js`, and the website's `ApplyFormFrame`, now set the attribute to the reported height as well.
- **Tests:** a new test in `EmbedFormParentBridgeTest`, written first. It failed first and now passes (10 tests).
- **Browser:** the attribute reads 709 on `/apply` and in a `file://` page.
- **The grey body in the same report:** the code saved in CMS > Site Settings (last updated 07:37) still has no `bg` option, so the form keeps its default grey. All three of the user's new variants (transparent, white, colour code) pass the save check. They just have not been saved yet.

## Follow-up 6 (same day): primary colour
- **Request:** "in iframe config add a primary color so that we can match website color."
- **New link option `color`:** six hex digits without the `#` (`color=2d9a96`), checked against the same pattern as the background (`EmbedFormDisplay::PARAM_PRIMARY`, `primaryColor()`).
  - `EmbedFormPageController` uses it in place of the branding `primary_color`, so `--brand-primary` and Bootstrap's `--bs-primary-rgb` both follow it. That covers buttons, the active step, headings and links.
  - An unreadable value leaves the brand colour as it is.
  - `--brand-secondary` (completed steps, the success colour) still comes from the branding.
- **Tests:**
  - `EmbedFormDisplayOptionsTest`: a primary colour replaces the brand colour, including the RGB value; unreadable values (`red`, `#2d9a96`, `2d9a9`, `red;}body{x`) leave `:root` unchanged.
  - `EmbedSnippetTest`: the colour is read from a pasted code.

  They were written first and failed first. `tests/Feature/EmbedForm`, `tests/Unit/EmbeddedForm` and `tests/Feature/Website` pass (423 tests).

## Follow-up 7 (same day): the step line
- **Reported:** "why line is break after 1 and before 8" in the form's 8-step bar.
- **Cause:** the line was drawn from 10% to 90% of the bar while the steps sit at its edges, so on a wide form it started after step 1 and stopped before step 8.
- **Fix:** every step now has the same width (`--stepper-node`): 72px with labels, 36px with counters only (below 992px), and 30px counters on a phone (below 576px), where eight 36px counters did not fit. The line runs from the first step's centre to the last one's: `calc(var(--stepper-pad) + var(--stepper-node) / 2)` on each side.
  - The progress fill's existing `(step - 1) / 7` now ends exactly on the current step.
- **Tests:** `EmbedFormDisplayOptionsTest::the_step_line_runs_from_the_first_step_to_the_last`, written first and failed first. 175 EmbedForm tests pass.
- **Browser:** at 1440, 1280, 900, 576, 375 and 320px, the gap between the line's ends and the first and last step centres is 0px. After moving to step 2, the progress ends on step 2's centre (within 1px).

## Cleanup (same day): code the finished feature no longer needs
- **`/website/embed-form` is back to its original shape**, `{form_title, form_url}`. The fields added to it during this work (`embed_url`, `auto_height`, `height`, `radius`, `EmbedFormService::hostApplySnippet()`) were removed, because the website now renders the code from `/website/settings`. The endpoint itself was there before and is kept.
- **`EmbedSnippet`** now only checks the pasted code and gives its host, key and script. Reading options, height and radius from it (`display()`, `height()`, `autoHeight()`, `radius()` and the bound constants) was removed, since nothing used them.
- **`EmbedFormDisplay`:** `toQuery()` and `background()` were removed, and `defaults()` is now private.
- **`EmbedFormConfig::EMBEDDED_QUERY_PARAM` was removed;** the tests use `EmbedFormDisplay::PARAM_EMBED`.
- **The old fixed snippet was removed:** `EmbedFormLocation::embedSnippet()` and the `embed_snippet` field in the config resource and its empty default. The admin builds the code itself from `direct_url` and `embed_script_url`.
- **Tests:**
  - `EmbedFormEmbeddedLayoutTest` was removed; `EmbedFormDisplayOptionsTest` covers the same ground.
  - `EmbedSnippetTest` now covers the host, key and script, and the refusals.
  - `ApplyFormEmbedCodeTest` now covers save and serve through `/website/settings`, including a code left with an old key not being served.
  - `WebsiteApplyFormTest` is back to its original version.
  - `EmbedFormLocationTest` checks `embedScriptUrl()` where it checked the snippet.
  - Result: `tests/Feature/EmbedForm`, `tests/Unit/EmbeddedForm` and `tests/Feature/Website` pass (413 tests).
