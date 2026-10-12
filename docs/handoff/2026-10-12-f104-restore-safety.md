# Restore safety (F104, was D13/O44)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F104 (was D13/O44)

## Problem
`backup:restore` overwrote the database immediately, in any environment.

## Solution
Asks for confirmation naming the database; refuses in production without `--force` (never prompts there).

## Files changed
- `app/Console/Commands/RestoreDatabaseCommand.php`
- `tests/Integration/Console/RestoreDatabaseCommandTest.php`

## Tests
- `Integration/Console/RestoreDatabaseCommandTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Any runbook that restores in production must now pass `--force`.
