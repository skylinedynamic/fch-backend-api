# Embed form: embedded layout (?embed=1)

**Date:** 2026-11-08

> **Superseded.** The single `embed` switch became the display options in `2026-11-08-embed-code-display-options.md`. `embed=1` still works, as a preset. The `$embedded` view flag, `EmbedFormConfig::EMBEDDED_QUERY_PARAM` and `EmbedFormEmbeddedLayoutTest` described below were removed in that note's cleanup.

## Problem
Review of the website's `/apply` page: "in apply page iframe design is not good."

`/apply` frames the form inside its own titled card. The form still drew its full standalone page inside that card, which gave four layers:
- the website card
- the grey page background
- the form's brand header card (logo, form title, "Powered by")
- the form card

The title showed twice. The copyright line and 40px of bottom padding left a large grey gap under the form.

## Solution
- **New query flag:** `?embed=1` (`EmbedFormConfig::EMBEDDED_QUERY_PARAM`). `EmbedFormPageController::show` passes `$embedded = $request->boolean('embed')` to the view.
- **What changes when it is set (`resources/views/embed-form/index.blade.php`):**
  - The body gets the `is-embedded` class.
  - The brand header card (`#embedBrandHeader`) and the copyright footer are not rendered.
  - The page background is transparent, with no `min-height` and no bottom padding.
  - The container has no 860px cap and only 4px padding, which keeps the focus rings of the outermost fields inside the iframe.
  - The main card (`.embed-main-card`) loses its border, shadow, background and padding.
  - The height reported to the parent adds 8px instead of 40px.
- **Opt-in only:** without the flag, nothing changes. This covers the form opened directly, "Open in a New Tab", and partner sites that frame it without the flag.

## Files changed
- `app/Models/EmbedForm/EmbedFormConfig.php`: `EMBEDDED_QUERY_PARAM` constant.
- `app/Http/Controllers/Web/EmbedFormPageController.php`: passes `embedded` to the view.
- `resources/views/embed-form/index.blade.php`: body class, conditional header and footer, embedded CSS, bottom space in `formHeight()`.
- `tests/Feature/EmbedForm/EmbedFormEmbeddedLayoutTest.php`: new.

## Testing
- **New test:** `EmbedFormEmbeddedLayoutTest`, 3 tests. They failed before the change and pass after:
  - `?embed=1` drops the header and copyright and keeps the form and stepper
  - without the flag, the branding is kept
  - a non-true value does not turn the layout on
- **Suite:** `php artisan test tests/Feature/EmbedForm/` passes, 155 tests.
- **Browser:** headless Chromium through the website's `/apply` at 1280, 768 and 375px:
  - the iframe is 709px tall at 1280px (was 1117px), with no inner scroll and no horizontal scroll
  - Continue on an empty step still scrolls the first error to 220px from the top, below the sticky header, and the iframe grows to fit the error messages

## Open items
- **Button colour:** the form's buttons use the partner branding's `primary_color` (dark navy for the host), not the website's teal. Changing it is a branding setting, and it also affects the portal.
- **Pint:** `concat_space`, `ordered_imports` and similar in the controller and model come from existing code and were not touched.
- Not committed.
