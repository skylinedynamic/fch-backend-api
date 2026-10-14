# Test isolation (F71, was O35)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F71 (was O35)

## Problem
Nothing in the base `TestCase` kept tests out of the real `storage/`; each test had to isolate itself (F49).

## Solution
`TestCase` runs every test on its own temp storage: `useStoragePath()`, disk roots and log paths re-pointed, Passport keys copied, folder removed in `tearDown()` (log handlers closed first, for Windows). Full suite run with a before/after listing of real `storage/app`: identical.

## Files changed
- `tests/TestCase.php`
- `tests/Unit/Support/TestStorageIsolationTest.php (new)`

## Tests
- `Unit/Support/TestStorageIsolationTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Tests that still isolate themselves (their own `useStoragePath()`) keep working; that code is now redundant and can be removed later.
