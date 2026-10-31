# BUG-106: signed-agreement reply says has_sign; Re-sign works with no signed copy

**Date:** 2026-10-29
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
- The admin's View Agreement page could not tell a record that never signed from one marked as signed (`partners.has_sign` / `borrowers.has_sign`) whose signed copy is missing. `GET common/agreement-sign/html/{userId}` returned only `content_html`, and it is empty in both cases.
- Clearing `has_sign` for a missing copy also failed. `POST common/agreement-sign/cancel/{userId}` threw "No signed agreement found for this user" (500) when there was no `agreement_signs` row.

## Solution
- **`getSignedHtml` reply:** now `{ content_html, has_sign }`. `has_sign` comes from the user's partner (a partner login) or client record. The new `AgreementService::isMarkedSigned()` reads it.
- **`cancelSign` (Cancel Agreement / Re-sign):**
  - Deletes the signed copy if there is one.
  - Clears `has_sign` either way, and sets the user's `is_refresh`.
  - Still errors when there is neither a copy nor `has_sign`.
- **Access is unchanged:** your own agreement, or the host's team for anyone's. Another partner still gets a 404.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: `cancelSign` tolerates a missing copy. New `isMarkedSigned()` and `signingEntity()`.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementSignController.php`: `getSignedHtml` returns `has_sign`.

## Tests
- Added `tests/Feature/Agreement/AgreementSignMissingFileTest.php` (7 tests). 5 were red first; the other 2 pin existing behaviour (cancel deletes a copy, another partner gets a 404).
  - The reply's `has_sign` for: missing copy, never signed, signed copy.
  - Re-sign with no copy, for a partner and for a client.
- Suites run: `tests/Feature/Agreement`, 24 passed.

## Open / follow-up
- The borrower portal's own `borrower/agreement-sign/signed-html` reply was not changed. It has no Re-sign.
