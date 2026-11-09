# Partner Documents: the file manager works in the private folder

**Date:** 2026-11-05
**Branch:** bugfix/round-2

## Problem
On the Partner profile's Documents tab:
- **Uploads went to public:** the file manager opened on `partners/{id}`, which is a public folder because the partner's logo and branding live there. A document uploaded there sat on the public disk, and its `/storage/...` link opened without a login.
- **Attach moved it away:** attaching the document moved it to the private `partner-documents/{id}`.
- **Then it disappeared:** after the move, the file manager no longer showed it.
- **Unattached files stayed public:** a file that was uploaded but never attached stayed on the public disk.

The user's rule: wherever a screen's files belong, the file manager opens on that folder, shows that folder's files, and uploads there. The disk is never shown.

## Solution
- **File manager (`FileManagerController::partnerMayOpen`):** `partner-documents/{id}` can be opened by the host team only, the same as the Documents tab's API (`v1/admin`). No other partner can open any partner's documents folder, its own included. The `uploads` disk already keeps the folder on the private disk and lists it with signed links.
- **Attach (`POST admin/partners/documents`):**
  - **New field:** an optional `entity_type`, the folder the picked paths are in. It must be one of `PartnerDocument::PICK_FOLDERS` (`partner-documents` or `partners`). Any other value is refused with `partner.validation.document_file.folder`.
  - **`partner-documents`:** the document is filed where it is, with no move, so the file manager still shows it.
  - **`partners`, or no `entity_type`:** works as before, and the file is moved to `partner-documents`.
- **Other screens:** checked, and no change was needed.
  - **Private, and already right:** the client and borrower portal documents, the funding application upload, the signature (`borrowers`) and Resources (`resources`).
  - **Public by design:** branding, profile pictures, blogs, SEO, site settings, credit accounts, vendor logos and employees.
  - **Agreement Builder logos (`agreements`):** stay public. Their URL is saved in the template, and a signed link would stop working after 60 minutes.

## Files changed
- `app/Http/Controllers/Api/V1/Global/FileManager/FileManagerController.php`: the host-only rule for `partner-documents`.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/StorePartnerDocumentRequest.php`: the `entity_type` rule and its message.
- `app/Models/Partner/PartnerDocument.php`: `PICK_FOLDERS`.
- `app/Services/FCH/Partner/PartnerDocumentService.php`: files a document from `partner-documents` in place, and `normalizePath()` takes the folder.
- `lang/en/partner.php`: `validation.document_file.folder`.
- `tests/Feature/FileManager/FileManagerApiTest.php`: 2 tests.
- `tests/Feature/Partner/PartnerDocumentAdminApiTest.php`: 2 tests.

## Testing
- **Test-first:** 3 of the 4 new tests failed before the change. The 4th, a host upload landing on the private disk, already passed, since routing by folder already worked.
- **Related suites:** `tests/Feature/Partner`, `FileManager`, `Files` and `Upload`: 195 passed.
- **Real local stack (script, cleaned up afterwards):**
  - uploaded to `partner-documents/37`: kept on the private disk, no public copy, and `/storage/...` returns 404;
  - the signed link returns 200;
  - after attaching, the path is unchanged, the link returns 200, and the file manager still lists the file.

## Open items
- **Production leftovers:** files already uploaded through the old Partner file manager and never attached are still in public `partners/{id}/`. They need listing and moving (or deleting) on the server.
- **Other folders are unchecked:** `partnerMayOpen` still does not check folder types other than `borrowers`, `employees` and `partner-documents`. For example, `partners/{id}` (public branding) can be opened for any partner.
- **Not committed.**
