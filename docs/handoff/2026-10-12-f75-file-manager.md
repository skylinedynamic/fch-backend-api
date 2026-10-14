# File manager (F75, was O8)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F75 (was O8)

## Problem
Downloading a missing file was a 500.

## Solution
Controller answers the service's 404/400 as that status.

## Files changed
- `app/Http/Controllers/Api/V1/Global/FileManager/FileManagerController.php`
- `tests/Feature/FileManager/FileManagerApiTest.php`

## Tests
- `Feature/FileManager/FileManagerApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
