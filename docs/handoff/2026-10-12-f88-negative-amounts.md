# Negative amounts (F88, was O38)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F88 (was O38)

## Problem
`currencyFormat(-50)` rendered `$-50.00`.

## Solution
`-$50.00`.

## Files changed
- `app/Helpers/helpers.php`
- `tests/Unit/Support/HelpersTest.php`

## Tests
- `Unit/Support/HelpersTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
