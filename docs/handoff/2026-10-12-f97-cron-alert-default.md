# Cron alert default (F97, was O50)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F97 (was O50)

## Problem
`cron_notify_emails` defaulted to two personal addresses (config and `.env.example`).

## Solution
No default; `.env.example` left blank with a comment.

## Files changed
- `config/app.php`
- `.env.example`
- `tests/Unit/Support/HelpersTest.php`

## Tests
- `Unit/Support/HelpersTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Set `CRON_ERROR_NOTIFY_EMAILS` in every environment that should receive scheduler alerts.
