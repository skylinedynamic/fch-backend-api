# Private files: client, application, partner and audit files off the public disk (BUG-016)

**Date:** 2026-11-04
**Branch:** bugfix/round-2
**Author:** Awlad Hossain

## Problem
BUG-016: every upload sat on the `public` disk, which `public/storage` links to. Any `/storage/...` URL was served straight by the web server, with no login and no access check:
- A partner, or anyone holding a URL, could open another partner's client's documents, credit reports, application files and signed applications.
- Links never expired, and nobody could tell who opened what.

Checked locally:
- `/storage/borrowers/169/credit_report_….pdf` returned 200.
- `/storage/audit/files/{sha256}.pdf` returned 200, and that hash is shown in the UI, the exports and the certificate.

The credit profile also took `pdf_url` from the client, and on read the server fetched it (`Http::get`): an SSRF.

## Solution
**The `uploads` disk** (`config/filesystems.php`, driver `folder-routing`):
- The app keeps every upload on it.
- `FolderRoutingAdapter` sends a path to the private disk when its first folder is listed in `config/files.php` `private_folders`, and to `public` otherwise.
- The disks are looked up on every call, so `Storage::fake('public')` in tests still works.
- `UploadsFilesystem::url()` gives a short-lived signed URL for a private file and the public URL for any other.
- Every `Storage::disk('public')`, `store*(…, 'public')`, `uploadFile`/`deleteFile(…, 'public')` and `'disk' => 'public'` in `app/` is now `uploads`. Three commands are the exception, because they read the old public files on purpose: `MoveFilesToPrivate`, `MoveSignedCopiesToAudit`, `PurgeFundingApplicationDownloadCopies`.
- One routing point means a call site can't be missed, which was the main risk. A folder goes private by being listed in config.

**Private folders** (`private_folders`; each disk is `*_DISK`, falling back to `FILES_PRIVATE_DISK`, then `local`):

| Folder | Holds |
| --- | --- |
| `audit` | Signed applications, certificates and seals |
| `borrowers` | Client documents, business logo and picture |
| `credit-reports` | iSoftPull reports, new folder |
| `funding-application` | Application documents |
| `partner-documents` | Partner documents, new folder |
| `embed_forms` | Embed form attachments |
| `attachments` | Activity and task files |
| `agreement-images` | Agreement logos and signatures (was `config/agreement.php`) |
| `resources` | The Resources page's files |

**Stay public:**
- `logos`, `site-settings`, `blogs`, `seo-settings`, `vendors`, `credit-accounts`, `contacts`, `users`, `employees`
- `partners/` (branding in `settings/` and `site/`, profile pictures)
- `resource-providers/`: the Resources providers' logos (new folder). A logo picked from `resources/` is kept as a public copy here on save (`ResourceProvider::publicLogoPath()`), so it keeps showing once `resources/` is private.
- Agreement images: `agreement-images` is listed in `private_folders` (`AGREEMENT_IMAGE_DISK`, as before); `config/agreement.php` was folded into `config/files.php` and removed, and `AgreementImageStore` uses the `uploads` disk. `public/agreements` is an empty leftover.

**Serving:**
- **Signed URLs:** the `local` disk is served at `/private-files` (never `/storage`), by signed URL only. `ProtectedFiles::temporaryUrl()` lasts `FILES_SIGNED_URL_MINUTES` (default 60, user decision). A request with no valid signature gets 403 (404 in production).
- **`FileUrl::for($path)`:** a private folder gets a signed URL, a public one gets its old `encodeUrl(asset('storage/…'))`. It's used by the accessors and resources that built `/storage` URLs by hand: `BorrowerDocument`, `FundingApplicationDocument`, `PartnerDocument`, `Resource`, the borrower, user, employee and partner `image`, `business_logo`, and the credit profile `pdf_url`.
- **`StoragePath::fromUrl()`:** turns a `/storage`, `/private-files` (query dropped) or plain path into a disk path. A URL elsewhere, or a path with `..`, gives null. It replaces five hand-written `/storage/` parsers.
- **Partner document download:** reads the uploads disk by path (no more `public_path`).
- **Mail attachment:** `BorrowerDocumentsUploadedMail` attaches from `uploads`.
- **Access:** the checks themselves are unchanged. Host sees all, a partner sees its own clients (`Borrower::scopeVisibleTo`), a borrower sees itself. A signed URL is only ever built while serialising a record an endpoint has already scoped. What changes is that a file can no longer be reached around those checks.

**Credit reports:**
- **Upload:** stored at `credit-reports/{borrower}/{uuid}.pdf`, a name that says nothing (`StoredFileName::anonymous`). The `BorrowerDocument` row holds that full path, and `BorrowerDocument::storagePathFor()` and `storagePath()` resolve both new and old rows.
- **Save:** a client-sent `pdf_url` is ignored. The report is the `document_id` uploaded for this client (`is_credit_report`, same borrower). Any other document gives 422 `borrower.credit_report_not_found`.
- **Read:** the report comes from the uploads disk only. No URL is ever fetched, which closes the SSRF.
- **Unchanged:** the borrower-portal copy of all client documents into an application behaves as before (the user said to leave it). It now resolves paths through `storagePathFor()`.

**Partner documents:** filing a document moves its file from `partners/{id}/` (the file manager folder) to `partner-documents/{id}/{name}_{random}.{ext}`. Downloads, activity-log attachments and delete follow `PartnerDocument::storagePathFor()`.

**Stored names:** `StoredFileName::make()` gives `{readable-name}_{8 hex}.{ext}`. The user wanted the name first and the random part after it.

**Upload names, time after the name (user request):** the file manager and activity-log attachments (host, partner, borrower) used `{time}_{name}.{ext}`. They now use `StoredFileName::timed()`: `{name}_{time}.{ext}`, for example `Bank_Statement_1793773507.pdf`, keeping letters, digits, `-` and `_` as typed. A taken name gets `_1`, then `_2` (user request). `StoredFileName::store()` picks the name and writes the file under a cache lock (`Cache::lock`, key `lockKey(folder, name)`, `CACHE_STORE=database`). Two uploads of one name at the same moment, from two requests, both stay; the second waits for the first, then takes `_1`. Used by the file manager and both activity-log services. Tests: `StoredFileNameTest` (numbering, three same-name stores, the lock blocks a second upload), `FileManagerApiTest` (updated, plus the same-second case). The frontends don't parse the time out of names.

**Moving existing files:**
- **`files:move-to-private {folder}`:** same paths. Byte-for-byte check; never overwrites a different file; deletes the public original only with `--delete-source`; safe to re-run.
- **`files:relocate {partner-documents|credit-reports|resource-providers}`:** new paths, with the records following. `resource-providers` copies each logo once however many providers show it, and never deletes a file that is also a resource's own file. For credit reports, profiles are matched by `pdf_url` too, since `document_id` is sometimes broken. Same safety rules, plus `--dry-run`.
- **Migration `2026_11_04_000000`:** `funding_application_documents.disk` changes from `public` to `uploads` (`down()` reverses it).

**Code standards pass (user request):** this pass covered the audit and private-files work, both committed and uncommitted.
- **No inline full namespaces:** every `\App\...`, `\Illuminate\...` and `\Exception` the work had added inline, in app, routes and tests, is now a `use` import.
- **No hard-coded disk names:** disk names come from `App\Support\Files\Disk`: `UPLOADS`, `PUBLIC`, `LOCAL` and `FOLDER_ROUTING_DRIVER`. This covers app code, `config/filesystems.php` and `config/files.php`.
- **No hard-coded folder names:** folder names come from `App\Support\Files\Folder`, covering every private and public top-level folder, plus `DOCUMENTS`, `SIGNED`, `DOWNLOADS` and `OLD_STORAGE`. The `private_folders` keys in `config/files.php`, the model `FOLDER` constants and the `files:relocate` kinds all use these constants. Audit subfolders are `FundingApplicationAuditTrail::FILES_FOLDER` and `SEALS_FOLDER`.
- **Messages moved to lang:**
  - `files.path_not_found` (two document-download controllers)
  - `files.store_failed` and `store_failed_log` (file manager)
  - `files.relocate.what.*` (command output)
  - `audit.purge.would_be_done` (in place of a hard-coded verb)
- **Not changed:** older lines this work did not change still have inline folder paths, for example the `"borrowers/{$id}/"` builders in the document services and `EmbedFormService`. Tests keep literal disk names as fixture values.

## Files changed
- **New:**
  - `config/files.php`, `lang/en/files.php`
  - `app/Support/Files/{FolderRoutingAdapter,UploadsFilesystem,FileUrl,StoragePath,StoredFileName}.php`
  - `app/Services/Files/ProtectedFiles.php`
  - `app/Console/Commands/{MoveFilesToPrivate,RelocateDocuments}.php`
  - the migration above
- **Config:**
  - `config/filesystems.php`: the `uploads` disk; the `local` disk at `/private-files`.
  - `config/audit.php`: `disk` and `folder` removed. The folder is `FundingApplicationAuditTrail::FOLDER` (`audit`) on the `uploads` disk, and its disk is `private_folders.audit` (`AUDIT_DISK`). A folder renamed in `.env` alone would have been sent to `public`, because the router knows folders by name.
  - `.env.example`.
  - `AppServiceProvider`: registers the driver.
- **App:** about 50 files moved from `public` to `uploads`, as listed above. The main changes are in:
  - `BorrowerController` (credit report)
  - `PartnerDocumentService` and `PartnerDocumentController`
  - `BorrowerDocument`, `PartnerDocument`, `FundingApplicationDocument`
  - the two activity-log services
  - `BorrowerDocumentsUploadedMail`
  - the borrower resources
  - `lang/en/borrower.php` (`credit_report_not_found`)

## Testing
- **New:**
  - `tests/Unit/Files/StoredFileNameTest.php` (8)
  - `tests/Unit/Files/StoragePathTest.php` (8; written together with the helper)
  - `tests/Feature/Files/ProtectedFilesTest.php` (6)
  - `tests/Feature/Files/UploadsDiskTest.php` (7)
  - `tests/Feature/Files/MoveFilesToPrivateCommandTest.php` (6)
  - `tests/Feature/Files/RelocateDocumentsCommandTest.php` (8)
  - `tests/Feature/Files/PrivateFoldersTest.php` (19): every private folder gets a signed URL, no `/storage` 404, and every public folder keeps its exact URL
  - `tests/Feature/CreditProfile/CreditReportPrivateStorageTest.php` (6): own folder with an anonymous name; a client `pdf_url` is ignored; nothing fetched (`Http::assertNothingSent`); signed URL works; an old public-URL report is still read; another client's report gives 422

  Except `StoragePathTest`, they failed first.
- **Updated:** tests that put or read private-folder files on `public` now use `uploads`:
  - `EmbedFormConversionDocumentsTest`, `RelocateConvertedDocumentsCommandTest`, `FileManagerApiTest`, `BorrowerDocumentsApiTest`
  - `ReminderAttachmentDownloadTest`, `PartnerDocumentAdminApiTest`, `BorrowerFundingApplicationStepsTest`
  - `EmbedFormDeleteSubmissionsTest`, `EmbedFormConvertExistingBorrowerTest`, `ClientCreditProfileAccessTest`
  - `SigningMethodTest`, plus the audit tests
- **Full suite:** 4778 passed, 2 skipped, 0 failed.
- **Browser (react-fch live specs, local API):**
  - New `e2e/sidebar/clients/client-private-files.live.spec.ts`: a client's document and credit report come with signed `/private-files` URLs that open (200), while `/storage/...` gives 404 and an unsigned URL gives 403.
  - The Credit Profile iframe holds the real PDF (`application/pdf`, starts `%PDF-`). It looks blank only in headless screenshots.
  - Clients, Partners, Funding Application (incl. Audit Trail), Embedded Form, Task & Reminders and Resources: 24 passed.
- **react-fch:** `ResourceForm` previews a just-picked provider logo by the file manager's URL (`providerLogoSrc`, `src/utils/imageHelpers.ts`), because `/storage/resources/...` no longer serves it. New `tests/utils/imageHelpers.test.ts` (6; failed first). Typecheck clean; Resources e2e (mock and live) 47/47. Otherwise unchanged. The credit report shows from the API's base64 `pdf`; documents and the file manager use the URLs the API gives, which are now signed for private files.
- **Local data:**
  - Migrated.
  - Copied to private and the public originals deleted: `audit` 12, `borrowers` 113, `funding-application` 31, `embed_forms` 15, `attachments` 1.
  - Credit reports relocated: 3 (74 records point at files never on this machine).
  - Partner documents: all 7 records point at files not on this machine.
  - `resources`: 10 copied and the public originals deleted (no record points at them). Provider logos: all 20 point at files not on this machine, so nothing to relocate locally.
  - `/storage/borrowers/…` and `/storage/audit/…` give 404; unsigned `/private-files/…` gives 403; a signed URL gives 200.
  - Two stale public-copy duplicates of relocated reports were removed. One was kept, because a live client document (365) points at it.
  - The old unreferenced `storage/app/private/documents/` was removed. The backup zip (`storage/app/private/Four Corner Funding/`, the scheduled `backup:run --only-db`) is left as it is, as decided.
  - `agreements:move-images-to-disk`: 23 rows updated, 14 distinct images now in `agreement-images/`; a re-run finds nothing.
  - The empty public `agreements/` and `Partner/` folders were removed.

## Open items
- **Deploy (back up storage first):**
  1. Deploy.
  2. `php artisan migrate`.
  3. `config:clear` and `queue:restart`.
  4. For each of `audit borrowers funding-application embed_forms attachments`: `files:move-to-private <f> --dry-run`, then without `--dry-run`.
  5. `files:relocate credit-reports`, `files:relocate partner-documents` and `files:relocate resource-providers`, each with `--dry-run` first. Include `resources` in step 4.
  6. Check downloads, credit profiles, the file manager and the audit trail.
  7. `files:move-to-private <f> --delete-source` for each folder.
  8. Confirm `/storage/borrowers/...` gives 404.

  Run relocate after move-to-private: relocate reads through the uploads disk, where `borrowers/` is already private.
- **File manager:** a partner's file manager still uploads into `partners/{id}/` (public) until a file is filed as a document.
- **`Chuck_Backup_code.png`:** a partner document. If it's on prod, the partner must rotate those backup codes.
- **The client's `pdf_url`:** react-fch still sends it on save. The API ignores it; drop it from the payload when that page is next touched.
