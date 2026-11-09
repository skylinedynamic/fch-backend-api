# Embed form: Reference ID no longer clipped on phones

**Date:** 2026-11-09
**Branch:** bugfix/iframe-application-website
**Author:** Awlad Hossain

## Problem
SCRUM-435 S435-BUG-06: at 390 × 844 the confirmation's green Reference ID badge was 493px wide inside a 320px form. It ran past the edge and was clipped, so only `Reference ID: 878e3768-049e-4625-8…` could be read.

Bootstrap's `.badge` sets `white-space: nowrap`, and a UUID has no spaces to break at.

## Solution
A CSS rule for `#successRefBadge`: `white-space: normal`, `overflow-wrap: anywhere`, `max-width: 100%`, and `line-height: 1.4` so wrapped lines do not touch (`.badge` sets 1). On a phone the ID now wraps inside the form. On a desktop it stays on one line.

A Copy button was discussed and left out, since QA did not ask for one.

## Files changed
- `resources/views/embed-form/index.blade.php`: the CSS rule.
- `tests/Feature/EmbedForm/EmbedFormSuccessMessageTest.php`: new case for the rule.
- `fch-website-react/e2e/apply-confirmation-mobile.spec.ts`: new.

## Tests
- **Website Playwright:** new spec measures the badge in `/apply` at 390px, 320px and 1280px. The two phone cases failed before the change; the desktop case passed before and after.
- **fch-api Feature:** the new case failed before the change.
- No Unit or Integration test was added: the change is CSS in a Blade view, with no PHP class to test.
- Suites run: website Playwright, 25 passed (Chromium); website Vitest, 18 passed; website typecheck clean; `tests/Feature/EmbedForm`, 238 passed. The whole API suite was not run.

## Open / follow-up
- **Real devices:** Chromium's phone-sized viewport only, not a real iPhone or Android, and not Safari. QA's report also lists these as not run.
- The submit response is mocked in the E2E, with a real-length Reference ID.
- Not committed.
