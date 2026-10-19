# Dead routes (F77, was O12/O30/O34)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F77 (was O12/O30/O34)

## Problem
`DELETE /common/users/{user}` (no `destroy()`), `GET /admin/cms/seo` (no `{id}`), `POST /admin/partners/documents/{id}` (`update` commented out) - all 500. No portal calls them.

## Solution
Routes removed (`apiResource('users')->except(['destroy'])`).

## Files changed
- `routes/common.php`
- `routes/admin.php`
- `tests/Feature/General/DeadRoutesTest.php (new)`

## Tests
- `Feature/General/DeadRoutesTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
