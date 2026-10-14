# SharePoint storage (F99, was O54)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F99 (was O54)

## Problem
A small upload treated Graph's 201 Created as a failure; the whole path was `rawurlencode`d (folder slashes became `%2F`).

## Solution
`successful()`; each path segment encoded separately.

## Files changed
- `app/Services/Global/SharePoint/SharePointService.php`
- `tests/Unit/Storage/SharePointServiceTest.php (new)`

## Tests
- `Unit/Storage/SharePointServiceTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
