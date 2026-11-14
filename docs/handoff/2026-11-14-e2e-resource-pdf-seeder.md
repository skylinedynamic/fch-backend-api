# E2E seeder for the resource PDF preview (R3-BUG-003)

Date: 2026-11-14

## Problem

R3-BUG-003: the PDF preview of a private resource file was blank in both portals. The fix is in the portals (client-fch and the admin panel): the preview reads the file through the download endpoint instead of framing the signed link.

The client portal's E2E runs against the local API. But every local resource names a file that is not on disk, so no resource there has a file to preview.

## Solution

`E2EResourcePdfSeeder` (local/testing only) adds a published Legal & Registration resource, "E2E Resource PDF", for partners and borrowers. It writes a real one-page PDF to `resources/e2e/e2e-resource.pdf` on the uploads disk (private).

- Re-running is safe: it updates the same resource and file.
- With `E2E_RESOURCE_PDF_REMOVE=1` it removes both.
- client-fch `e2e/sidebar/resource-hub/resource-preview.spec.ts` runs it before the spec and removes the resource afterwards.

**No API change was needed.** The download endpoints already check who may download what. `tests/Feature/Resource/ResourceLibraryApiTest.php` covers:
- each portal downloads what is meant for it
- nothing else downloads
- the host's team also downloads unpublished resources

## Files changed

- `database/seeders/E2EResourcePdfSeeder.php` (new)

## Testing

- **Run locally:**
  - The resource's `file_url` is a signed `/private-files/resources/e2e/e2e-resource.pdf?expires=…&signature=…` link. The file is `application/pdf`.
  - The client E2E passed with it.
  - Cleanup was checked: no resource and no file were left.

## Open items

- None for the server. The seeder refuses to run outside local/testing.
