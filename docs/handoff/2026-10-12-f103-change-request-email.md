# Change-request email (F103, was D12)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F103 (was D12)

## Problem
The new (and old) value went out in plain email, SSN and EIN included.

## Solution
`BorrowerChangeRequest::maskedValue()`: SSN `***-**-1234` (last-4-only fully masked), EIN `**-***1234`; DOB and other fields as is. Used by the borrower decision email and the host "change requested" email.

## Files changed
- `app/Models/Borrower/BorrowerChangeRequest.php`
- `app/Services/Profile/ChangeRequestNotifier.php`
- `app/Mail/Partner/NewChangeRequestSubmittedMail.php`
- `tests/Unit/Profile/ChangeRequestNotifierTest.php`
- `tests/Integration/Mail/RecordMailRenderTest.php`

## Tests
- `Unit/Profile/ChangeRequestNotifierTest`, `Integration/Mail/RecordMailRenderTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
The admin change-request screen still shows full values (unchanged).
