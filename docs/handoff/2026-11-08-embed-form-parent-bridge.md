# Embed form tells the framing page its height and where to scroll

**Date:** 2026-11-08

## Problem
The website's `/apply` page shows the embed form (`/form/{key}`, `resources/views/embed-form/index.blade.php`) in an iframe, and the page around the iframe cannot see the form's height. This caused two problems:
- **Height.** The iframe had a fixed height, so the form scrolled inside a box, or left an empty gap under a short step and under the success screen.
- **Scrolling.** Moving between steps, showing the first invalid field and showing the success screen all called `window.scrollTo` or `scrollIntoView` inside the frame. In a frame sized to its content, that moves nothing, so the visitor stayed at the bottom of a long step.

The FCH brief asks for automatic, secure resizing of the iframe.

## Solution
Added a small bridge to the form's script. It runs only when the form is framed: `window.parent !== window` and there is a referrer.
- **Messages:** `{ source: 'fch-embed-form', type: 'resize', height }` and `{ source: 'fch-embed-form', type: 'scroll', top }`. They carry only layout numbers, never a field value.
- **Target:** messages are posted only to the framing page's origin (`new URL(document.referrer).origin`), never `'*'`.
- **What is measured:**
  - The height is measured from `body > .container`, plus any open Select2 dropdown or flatpickr calendar.
  - The body is not used because `body` has `min-height: 100vh`, which inside an iframe equals the iframe's own height. Watching body meant the height could grow but never shrink. This was found in QA: the success screen stayed 2179px tall.
  - A `ResizeObserver` watches the same container, with extra checks on click, keyup and focusin for dropdowns.
- **Scrolling:**
  - `scrollFormTo(top)` and `scrollFormToElement(el)` replace the four scroll calls: `showStep`, the success screen, and the two first-invalid-field jumps.
  - When the form is framed they ask the parent to scroll. When it is opened directly they scroll the window as before.
- **No jump on load:** `showStep(step, scroll = true)`. The first step on load is `showStep(1, false)`, so opening `/apply` does not pull the visitor down to the form.

## Files changed
- `resources/views/embed-form/index.blade.php`: the bridge (`PARENT_ORIGIN`, `postToParent`, `formHeight`, `reportHeight`, `scrollFormTo`, `scrollFormToElement`, the observer); the scroll calls routed through it; the first `showStep` call made without scrolling.
- `tests/Feature/EmbedForm/EmbedFormParentBridgeTest.php`: new, 5 tests.

## Testing
- **`EmbedFormParentBridgeTest`:** written first. It failed before each part of the change and passes after. It checks:
  - the messages exist
  - they are sent only to the referrer origin, never `'*'`, and only when framed
  - step changes, the success screen and errors scroll through the bridge
  - nothing scrolls on load
  - the container is observed, not the body
- **Suite:** `tests/Feature/EmbedForm` passes, 152 tests.
- **Live, from the website's `/apply` (local API, real DB):**
  - Messages came only from `http://127.0.0.1:8000` and carried only `source`, `type`, `height` and `top`.
  - The frame height followed the content (e.g. 1117px on step 1 at 1280px), with no inner scrollbar.
  - The page did not move on load.
  - Continue on an empty step 1 brought the first invalid field into view.
- **Full submissions, all 8 steps with a PDF upload:** run in Chromium at 1280px, Chromium at 375px using only the keyboard, Firefox at 1280px and WebKit at 390px.
  - All returned 201 and showed the confirmation screen.
  - The frame shrank to about 800px afterwards.
  - These ran against a second `php artisan serve` on :8001 with `MAIL_MAILER=log QUEUE_CONNECTION=sync`, so no mail left the machine.
  - Each run wrote only to `embedded_form_submissions`, `embedded_form_attachments` and one `notifications` row. No credit-report table was touched.
  - All rows, uploaded files and notices were deleted afterwards.

## Open items
- `origin_url` on these submissions was `null`: the iframe's submit request carried no Referer. Recording the website as the source (brief section 6) still needs its own field.
- On a partner's own site the bridge also posts to that site. The messages are harmless (layout only) and are ignored unless that page listens for `source: 'fch-embed-form'`.
- The success copy says "will contact you shortly". The brief asks for no unverified response-time promise; this belongs to section 7.
- Not committed.
