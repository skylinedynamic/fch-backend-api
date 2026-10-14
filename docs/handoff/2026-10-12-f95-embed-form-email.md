# Embed-form email (F95, was O48)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F95 (was O48)

## Problem
No sign-in button when the partner had no `borrower_domain`.

## Solution
Falls back to the main portal login, like the other borrower emails.

## Files changed
- `app/Mail/EmbedForm/EmbedFormConverted.php`
- `tests/Integration/Mail/RecordMailRenderTest.php`

## Tests
- `Integration/Mail/RecordMailRenderTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
