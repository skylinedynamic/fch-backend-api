# Funding application audit trail (replaces the download-only Audit History)

**Date:** 2026-11-03
**Branch:** bugfix/round-2
**Author:** Awlad Hossain

## Problem
The admin "Audit History" (`download_histories`) recorded only downloads:
- No application changes, status changes, signing or documents were recorded.
- A re-download from the history itself was not recorded.
- Every download saved a copy of the PDF/ZIP to the public disk at a guessable path, served without login (`/storage/funding-applications/{id}/downloads/Funding_Application_FUND-….pdf`, SSN, EIN and all). The next download overwrote it, so "download from history" gave the wrong file.
- Rows could be rewritten without trace.
- The table cascaded on application delete.
- `sort_by` went straight into `orderBy`.

## Solution
**Scope:** only the audit feature. No funding application behaviour changed. Status update, edit, signing, PDF generation, passwords and document upload are untouched. Observers only record.

**Table `funding_application_audit_events`**
- Append-only: the model throws on update or delete.
- Hash-chained per application: `prev_hash` → `hash`, sha256 over every recorded column, JSON keys canonicalised because MySQL reorders them.
- `occurred_at` is in UTC.
- No foreign key, so the trail outlives the application row.
- It replaces `download_histories`. The migration imports every old row as a `legacy` event:
  - `generated` rows become `signed_copy_kept`.
  - Download rows become `downloaded`.
  - Any old password is never carried over.
  - Then it drops the table. `down()` recreates it empty.

**What is recorded** (observers in `app/Observers/Audit/`, registered in `AppServiceProvider`)
- **`created`, `updated`:** each changed field, old → new. Bookkeeping fields are ignored (`config/audit.php`). SSN and driver's licence are masked to their last 4.
- **`status_changed`:** status plus its note.
- **`signed`, `signature_cleared`:** the signature blob is never copied into the trail.
- **`signed_copy_kept`:** at signing. The signed application and certificate are **written straight to the audit folder** (`audit/files/{sha256}.pdf`), which replaces `funding-applications/{id}/signed/`. `signature_data`'s `signed_application_path` and `certificate_path` point there, and their count numbers the next signed version, as `download_histories` used to. A download still hands out the signed copy, read from the audit disk; a path signed before the move is read from the public disk.
- **`document_added`, `document_removed`:** recorded by name, type, size and SHA-256 only (`FundingApplicationAuditTrail::fingerprint()`). **No copy of a document is kept** (user decision: the record is enough; a copy doubled document storage). The services delete the file before the record, so the removal takes the hash from the add event. A document event never hands out a file (`FundingApplicationAuditEvent::DOCUMENT_EVENTS`).
- **`co_borrower_added`, `co_borrower_updated`, `co_borrower_removed`.**
- **Commission is not recorded** (user decision): no commission observer, no `commission_changed` event. The test "commission changes are not recorded" guards it.
- **`downloaded`:** file type, password used or not (never the password), version, certificate and browser, with the sha256 of what was handed out. **No copy is saved any more.**
- **`deleted`, `restored`.**
- **Who, from where:** actor id, name and type, IP and user agent on every event.
- **A failure to record never fails the change:** it is logged.

**Audit folder:** disk and folder come from `config/audit.php` (`AUDIT_DISK`, default `public`, as decided for now). Files are never linked to directly. They are only served by the API, behind the same access check as the trail.

**API** (partner-portal routes, `FundingApplicationAuditTrailController`)
- `GET common/request-application/funding-application/{id}/audit-trail`:
  - newest first, with filters `event`, `search`, `sort_dir`
  - `per_page` capped at 100; an unknown event or sort gives 422
  - returns `integrity` (chain check)
- `GET …/audit-trail/export?format=csv|pdf`: the same filters, as CSV (the default) or PDF. The PDF (`FundingApplicationAuditTrailPdf`, view `pdf.audit-trail`, A4 landscape) carries the host's branding like the application PDF, the integrity result, and per event the date (UTC), event, who and role, IP, each change old → new, the details and the file's SHA-256. An unknown format gives 422 (`FundingApplicationAuditTrailRequest::EXPORT_FORMATS`). CSV and PDF share the change and detail text (`AuditLabels::changes()`, `context()`).
- `GET …/audit-trail/{eventId}/file`: the copy the trail kept.
- Access: the host reads every application's trail, a partner its own (by the partner the event was recorded under), a client none.

**Commands**
- `audit:verify [--application=]`: walks the chains and fails on a break.
- `audit:seal`: scheduled daily at 23:55. Writes `audit/seals/{date}.json` with each application's event count and last hash, so a removed last event or a rewritten chain shows.
- `funding-applications:purge-download-copies [--dry-run]`: deletes `funding-applications/*/downloads/` and the mistaken `storage/funding-applications/`. **Never `signed/`.**
- `audit:move-signed-copies [--dry-run]`: moves every signed copy from `funding-applications/{id}/signed/` to `audit/files/{sha256}.pdf`, including copies no record points to any more (edited after signing; still the evidence of that signing). It then updates the paths in `signature_data` and `previous_signature_data` (written directly, no model events) and removes `funding-applications/`. A file is deleted only after its audit copy is written and matches byte for byte. A record whose file is missing is reported and left alone. A file that is not a signed copy is reported and kept. Re-running changes nothing.
- An imported event names its file by hash only: once the copy is in the audit folder, the trail hands it out from `audit/files/{hash}.pdf` (`FundingApplicationAuditTrail::storedFile()`).

**Removed**
- The `DownloadHistory` model and resource, and the `downloadHistories` relation.
- `getAuditHistory`, `downloadFromHistory` and their routes (`POST …/audit-history`, `GET download-history/{id}/download`).
- `download_histories` in the application detail response.
- The download-copy saving.

**No hard-coded strings:** every message, CSV/PDF word, yes/no, the empty-value dash and the export file name are in `lang/en/audit.php`. Export formats, their MIME types and the sort directions are constants on `FundingApplicationAuditTrailRequest`; the default page size, export date format and PDF paper are in `config/audit.php`.

## Files changed
- **New:**
  - `config/audit.php`, `lang/en/audit.php`
  - migration `2026_11_03_000000_create_funding_application_audit_events_table.php`
  - `app/Models/Audit/FundingApplicationAuditEvent.php`
  - `app/Services/Audit/FundingApplicationAuditTrail.php`
  - `app/Observers/Audit/*` (3 files: application, document, co-borrower)
  - `app/Support/Audit/AuditLabels.php`
  - `app/Http/Controllers/Api/V1/FCH/Common/Audit/FundingApplicationAuditTrailController.php`
  - `app/Http/Requests/Api/V1/FCH/Common/Audit/FundingApplicationAuditTrailRequest.php`
  - `app/Http/Resources/FCH/Common/Audit/FundingApplicationAuditEventResource.php`
  - `app/Console/Commands/{VerifyAuditTrail,SealAuditTrail,PurgeFundingApplicationDownloadCopies}.php`
  - `app/Services/Audit/FundingApplicationAuditTrailPdf.php`, `resources/views/pdf/audit-trail.blade.php` (PDF export)
- **Changed:**
  - `FundingApplicationDocumentService.php`: downloads and signed copies are recorded in the trail; no download copies; version counted from the trail.
  - `RequestApplicationController.php`, `RequestApplicationService.php`: old audit-history code removed.
  - `FundingApplication.php`: `auditEvents()` replaces `downloadHistories()`.
  - `FundingApplicationResource.php`, `AppServiceProvider.php`, `routes/common.php`, `routes/console.php` (seal schedule).
- **Deleted:** `app/Models/Borrower/DownloadHistory.php`, `app/Http/Resources/FCH/Common/Application/DownloadHistoryResource.php`.

## Testing
- New:
  - `tests/Feature/Audit/FundingApplicationAuditTrailTest.php` (25)
  - `tests/Feature/Audit/AuditTrailCommandsTest.php` (4: verify, seal, purge keeps `signed/`, legacy import without passwords)
  - `tests/Unit/Audit/FundingApplicationAuditTrailTest.php` (3: JSON key order, masking, a failed record never fails the change)
- Updated to the new trail, with the same guarantees:
  - `ApplicationDownloadPasswordTest`: the password is never stored. The old "remove stored passwords" migration test was replaced by the import test, which strips them.
  - `RequestApplicationReviewTest`: own applications only; a kept file only for your own application.
  - `ScheduleTest`: `audit:seal`.
  - `EmbedFormConversionDocumentsTest`, `RelocateConvertedDocumentsCommandTest`: these counted every file on the disk, so they now exclude the audit copy and check it is kept exactly once.
  - Two tests that eager-loaded `downloadHistories`.
- The new tests failed first (missing classes, schedule).
- Full suite (`php artisan test`, final run, after the PDF export, the document-hash-only change and the lang/constant cleanup): 4618 passed, 2 skipped, 0 failed.
- Documents by hash only (failed first): "a document is recorded by its hash and no copy is kept", "the same file added twice has the same hash", "a document's event has no file to download". The file-access tests now use a kept signed copy. The two embed-form tests count no audit copy.
- PDF export, in `FundingApplicationAuditTrailTest` (4, failed first): it downloads as `application/pdf` under `audit-trail-application-{id}.pdf`; the view lists the event, its field change and the actor; an unknown format gives 422; another partner gets 404. A sample rendered from local application 61 was checked by eye.
- Also new: `tests/Feature/Audit/MoveSignedCopiesToAuditTest.php` (8), and in `SignedCopyPreservationTest`, "the signed copies are kept in the audit folder by their hash". In `FundingApplicationAuditTrailTest`, "an imported event's file hands out from the audit folder by its hash".
- **Local DB:**
  - `php artisan migrate`: 100 `download_histories` rows imported as legacy events.
  - `audit:verify`: 36 applications, every chain intact on MySQL.
  - `purge-download-copies`: deleted 7 download copies, 10 signed files untouched. The old download URL no longer serves the PDF.
  - `audit:move-signed-copies`: moved the 10 signed files (2 referenced, 8 no longer referenced). The records for 65 and 68 point to `audit/files/`, and their sha256 matches. 30 old records point at files never on disk locally, and are left as they are.
  - Two stray test files (`funding-applications/1/*-FUND-TEST-LOCK.pdf`, from 16 Oct) were deleted by hand. `funding-applications/` is gone.

## Merge with `bugfix/embed-form-application-edit`
- `routes/common.php`: kept the audit-trail routes and the branch's `assign-lender`; dropped the branch's old `POST …/audit-history` (replaced by the trail).
- The branch's Assign Lender saves `lender_ids` / `assign_lender` on the application, so the trail records it as "Application updated". Labels added (`audit.fields.lender_ids`, `assign_lender`) and a test in `FundingApplicationLenderAssignmentTest` ("assigning a lender is recorded in the audit trail").
- `FundingApplicationAuditLogger` (the activity log, not this trail) and its embed-form conversion entry are the branch's, unchanged.

## Open items
- **The audit folder is on the public disk** (decided for now). Names are unguessable hashes and the files are served only by the API, but the URL works without login if leaked. Set `AUDIT_DISK` to a private disk or S3 with Object Lock when BUG-016 is fixed.
- **Signed copies are now in the audit folder:** still the public disk (`AUDIT_DISK`). Making that private is part of BUG-016.
- **Views aren't recorded:** only data changes and downloads, as decided.
- **No retention or disposal rule yet:** needs legal's periods.
- **Deploy (back up storage first on prod):**
  1. `php artisan migrate`
  2. `php artisan funding-applications:purge-download-copies --dry-run`, review the list, then run it without `--dry-run`
  3. `php artisan audit:move-signed-copies --dry-run`, review the list, then run it without `--dry-run`
  4. `php artisan audit:verify`
