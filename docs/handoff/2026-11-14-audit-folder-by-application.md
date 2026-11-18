# Audit folder: closed to the file manager, signed copies kept by application

Date: 2026-11-14

## Problem

The audit folder (`storage/app/private/audit`) holds every signed funding application PDF and its signature certificate. Each one carries the borrower's personal and financial details and their signature.

1. **Reachable through the file manager.** `FileManagerController::partnerMayOpen()` returned `true` for any folder type it did not know. Anyone signed in could call `POST /api/v1/filemanager/list` with `{entity_type: "audit", entity_id: 1, get_all: true}` and get every application's signed PDFs listed, with signed download links. Upload, rename, delete and paste went through the same check. This is the worst case of R3-BUG-002; the other folder types are still open.
2. **Flat and anonymous.** Every partner's copies sat side by side as `audit/files/{sha256}.pdf`. Nothing in a path said which application, version or kind of copy it was.

## Solution

**File manager.** The middleware first refuses any request whose root's first segment is `audit`. This covers `audit`, `audit/files`, `./audit` and `audit\files`, every user (the host included) and all eight actions. Since the R3-BUG-002 allowlist (`2026-11-14-file-manager-folder-allowlist.md`) this is the allowlist's job: `audit` is not on it. Signed copies are still handed out by the audit trail's own endpoint, which checks whose application it is.

**New signed copies.** Signing (`FundingApplicationDocumentService::generateAndLockFinalDocuments`) now keeps both PDFs through `FundingApplicationAuditTrail::keepSignedCopy()`:
```
audit/applications/{application id}/v{version}/application-{sha256}.pdf
audit/applications/{application id}/v{version}/certificate-{sha256}.pdf
audit/applications/{application id}/unversioned/…   (no version given)
```
- The hash stays in the name. An existing copy is never overwritten, and changed bytes can never pass for it.
- The signed copy event and `signature_data` record the new path.

**Old copies: `php artisan audit:organize-files [--dry-run]`.** This one-off command moves the flat `audit/files/{sha256}.pdf` copies under their application and version, and points `signature_data` / `previous_signature_data` at the new paths.

Which application, version and kind each copy is comes from three passes:
1. `signed_copy_kept` events that name a flat path.
2. The signature records.
3. Imported events (old download history) that name a copy by hash only. These are skipped when the application already has that copy, so a record's `v1` and an import's `v1.0` don't both get one.

Safety rules:
- **Hash check.** A copy is written only from a flat file that still matches its hash, and is read back after writing.
- **Delete last.** A flat file is deleted only when every copy of it is in place.
- **Mismatches and orphans are kept.** A file that doesn't match its hash, or that nothing names, is reported and kept.
- **Shared files.** A file shared by two applications goes under each.
- **Re-running is safe.**
- **The audit events are never touched.** Their hashes cover `file_path`, so every chain still verifies; `storedFile()` finds the moved copy by hash.
- **Seals are not touched.**

**Finding a file.** A download event names what it handed out by hash only. `storedFile()` now looks in this order:
1. the event's own path
2. the copy with that hash under the event's **own** application (never another application's)
3. the old flat `audit/files/{sha256}.pdf`

Each application's listing is read once per request. For this, the trail is bound `scoped` in `AppServiceProvider`.

## Files changed

- `app/Http/Controllers/Api/V1/Global/FileManager/FileManagerController.php`: the audit folder is refused (first by `namesAuditFolder()`, now by the allowlist).
- `app/Services/Audit/FundingApplicationAuditTrail.php`:
  - new `APPLICATIONS_FOLDER`, `UNVERSIONED_FOLDER`, `keepSignedCopy()` and `keptCopyPath()`
  - `storedFile()` searches by hash under the application
  - `FILES_FOLDER` documented as the old location
  - `keepContents()` unchanged; `audit:move-signed-copies` still uses it
- `app/Models/Audit/FundingApplicationAuditEvent.php`: `FILE_NAMES` / `FILE_NAME_OTHER`.
- `app/Console/Commands/OrganizeAuditFiles.php` (new): `audit:organize-files`.
- `app/Console/Commands/MoveSignedCopiesToAudit.php`: docblock says to run `audit:organize-files` after it (it still writes flat).
- `lang/en/audit.php`: `organize.*` messages.
- `app/Services/FCH/Common/Application/FundingApplicationDocumentService.php`: signing uses `keepSignedCopy()`; docblocks updated.
- `app/Providers/AppServiceProvider.php`: `FundingApplicationAuditTrail` bound `scoped`.
- Tests:
  - `tests/Feature/FileManager/FileManagerApiTest.php`: +1, `nobody_opens_the_audit_folder_in_the_file_manager` (5 callers × 5 roots × 8 actions).
  - `tests/Feature/Audit/FundingApplicationAuditTrailTest.php`: +3. A download's file is found under its application by hash; another application's copy is never found; a signed copy is never overwritten.
  - `tests/Feature/FundApplication/SignedCopyPreservationTest.php`: expects the new layout, and `v2` for a second signing.
  - `tests/Feature/Audit/OrganizeAuditFilesTest.php` (new, 11 tests). It covers:
    - a dry run changes nothing
    - copies move and the records follow
    - the events are untouched and the chain verifies
    - the trail still hands out each moved copy
    - a second run changes nothing
    - a mismatched file stays
    - an orphan is kept
    - a shared copy goes under each application
    - unversioned copies
    - imported events
    - seals are untouched

## Testing

- **Written first and seen failing:** 6 new or changed tests, then the 11 `OrganizeAuditFilesTest` tests (the command did not exist).
- **FileManager, Audit, FundApplication and Files suites plus `RequestApplicationReviewTest`:** 395 tests, all passed (1 skipped).
- **Full suite:** 5,485 tests, all passed (2 skipped). This run was before `audit:organize-files` was added; that command's tests ran in the 395 above.
- **`audit:organize-files --dry-run` on the local database:** would write 22 copies across 11 applications and remove 22 flat files; none would be kept for a mismatch.

## Open items

- **Server run, in this order** (no migration):
  1. `php artisan audit:organize-files --dry-run`. Check the last line, and any `mismatch`, `conflict` or `Nothing names this file` lines.
  2. `php artisan audit:organize-files`
  3. `php artisan audit:verify`
- **Before S3 Object Lock:** run this before Object Lock is turned on for `AUDIT_DISK`. Locked objects cannot be deleted.
- **Seals:** these still go to `audit/seals/{Y-m-d}.json`; this change does not touch them.
- **R3-BUG-002:** the other folder types are closed by the allowlist; see `2026-11-14-file-manager-folder-allowlist.md`.
