# Mail branding (F94, was O47)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F94 (was O47)

## Problem
`AppointmentNotification` ignored its `$partnerId`; `UnderwritingSubmitted` logged its branding at info.

## Solution
Partner id passed through; log line removed.

## Files changed
- `app/Mail/Appointment/AppointmentNotification.php`
- `app/Mail/FundingRequest/UnderwritingSubmitted.php`
- `tests/Integration/Mail/RecordMailRenderTest.php`

## Tests
- `Integration/Mail/RecordMailRenderTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
