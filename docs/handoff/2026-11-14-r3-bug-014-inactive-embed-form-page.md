# R3-BUG-014: an inactive embed form says it isn't taking applications

Date: 2026-11-14

## Problem

Embedded Form › Configuration › Active switched off. The admin label then reads "Disabled — Public form will show an inactive message". But the public link (`/form/{public_key}`) showed the bare "404 NOT FOUND" page, the same as a link that never existed. A visitor, or the partner's website around the iframe, could not tell the form was paused.

`EmbedFormPageController::show()` looked the form up with `where('is_active', true)`, so an inactive form and an unknown key both ended in `abort(404)`.

## Solution

- **Lookup.** The form is looked up by key alone. Only a key no form has is still a 404.
- **Inactive page.** A form that is switched off renders the new `embed-form.inactive` view. It has:
  - the partner's logo, or its initial
  - the partner's name, and the partner colour (or the embed code's colour)
  - "This form is not accepting applications right now"
  - "Please check back later, or contact {partner} directly."

  There is no form and no submit button. The status is 200, so browsers show the page inside an iframe rather than their own error page.
- **Framing.** The page keeps the form's framing rule (CSP `frame-ancestors` from Allowed Domains). It is now shared with the live form through `framed()`.
- **Submissions** are still refused for an inactive form (`EmbedFormService`, unchanged).

## Files changed

- `app/Http/Controllers/Web/EmbedFormPageController.php`:
  - looks the form up by key alone
  - renders the inactive page for a form that is off
  - the framing rule moved into `framed()`
- `resources/views/embed-form/inactive.blade.php` (new): the paused page.
- `lang/en/embed_form.php`: `inactive_title`, `inactive_message`.
- `tests/Feature/EmbedForm/EmbedFormInactivePageTest.php` (new, 4):
  - an inactive form shows the message in the partner's name, with no form and no 404
  - it keeps its framing rule
  - an unknown key is still 404
  - an active form still loads

## Testing

- **Seen failing first:** the 2 inactive-page tests. The 404 and active-form tests passed before too.
- **`tests/Feature/EmbedForm`:** 244 passed.
- **Full suite (after R3-BUG-011 to 014):** 5,562 tests, all passed (2 skipped).

## Open items

- **No admin change needed.**
- **Wording:** "not accepting applications" is in `lang/en/embed_form.php` if product wants different text.
