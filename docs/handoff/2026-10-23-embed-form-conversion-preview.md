# Embed form — conversion preview endpoint

**Date:** 2026-10-23
**Branch:** `hotfix` (uncommitted)
**Author:** awlad

## Problem
The admin panel's Convert modal for an embed-form loan application always opened with the "new free-plan borrower" text. The API only revealed an existing borrower with the same email after Confirm, answering `POST .../convert-to-borrower` with 409 `existing_borrower_confirmation_required`. So the first message the partner saw could be wrong, and there was no way to say up front whether a new client would be created.

## Solution
- New read-only `GET /api/v1/common/embed-forms/submissions/{id}/conversion-preview` (same `permission:convert application` middleware as convert). It answers:
  - `{ mode: 'existing_borrower', existing_borrower: {…summary} }`, or
  - `{ mode: 'new_borrower', existing_borrower: null }`.
- It refuses exactly like convert:
  - 404 for an unknown submission
  - 403 for another partner's submission
  - 422 when already converted, when the email is missing or belongs to staff, or when the borrower belongs to another partner
- It writes nothing.
- `EmbedFormService`:
  - new `CONVERSION_MODE_NEW` / `CONVERSION_MODE_EXISTING` constants
  - new `conversionPreview()`
  - the email/staff/other-partner checks moved out of `convertToBorrower()` into a private `convertibleBorrower()` that both use, so preview and convert cannot disagree
- `EmbedFormController`: the not-found, access and already-converted checks moved into a private `convertibleSubmission()`, used by `conversionPreview()` and `convertToBorrower()`.
- The 409 confirmation on convert is unchanged. It stays as the safety net for a borrower created between preview and confirm.

## Files changed
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: mode constants, `convertibleBorrower()`, `conversionPreview()`. `convertToBorrower()` now uses the shared check.
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`: `convertibleSubmission()` and `conversionPreview()`.
- `routes/common.php`: the `submissions/{id}/conversion-preview` route.
- `tests/Feature/EmbedForm/EmbedFormConversionPreviewTest.php`: new.

## Tests
- New `EmbedFormConversionPreviewTest`, 9 tests:
  - new email → `new_borrower`
  - existing client → summary
  - writes nothing
  - staff email → 422
  - other partner's client → 422
  - already converted → 422
  - other partner's submission → 403
  - unknown → 404
  - no permission → 403
- They were red before the route existed (8 of 9 failed).
- `vendor/bin/phpunit tests/Feature/EmbedForm`: **127 passed**, existing convert tests included.
- Local note: the PATH PHP (8.2) has no `pdo_sqlite`. Run with `php-8.3.27 -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit …`. `artisan test` drops the `-d` flags in its child process.

## Open items
- The admin panel side is in `Fch-admin-frontend-react` (`docs/handoff/2026-10-23-embed-form-convert-client-message.md`).
- Not committed. The work sits on the current `hotfix` branch.
