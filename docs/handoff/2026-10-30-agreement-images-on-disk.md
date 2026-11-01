# Agreement images kept on disk, not as base64 in the database

**Date:** 2026-10-30
**Branch:** agreement-in-config
**Author:** Awlad Hossain

## Problem
Agreement templates and signed agreements kept their images (company logo, signature) as base64 data URIs inside the HTML/JSON columns. Local data showed what that costs:
- a template averaged ~1 MB, because the logo was stored twice (`template_html` and `template_json`);
- a signed agreement averaged 1.8 MB and reached 6.2 MB. One signature was a 5.5 MB phone photo, and the template logo was copied again into every signature.

In total, 18 signatures took 31 MB, and the size grows with every borrower. Reading these rows also loads megabytes into PHP memory.

## Solution
- `AgreementImageStore` moves each image to the agreement image disk, named by the SHA-256 of its bytes, so an identical image is stored once. In the text it leaves `agreement-image:{sha256}.{ext}`. `inline()` puts back the identical data URI.
- An image is only moved when it can be put back byte for byte: a known type, and strict, canonical base64. Anything else stays inline. A signed agreement therefore reads back exactly as it was signed.
- The `AgreementImages` Eloquent cast is applied to `agreement_templates.template_html`/`template_json` and `agreement_signs.content_html`. Every reader and writer (save, show, check, submit, signed HTML, PDF, `borrowerSignatureBlock`) goes through the model, so **the API's requests and responses are unchanged and neither portal needed a change**.
- References are matched as `[a-f0-9]{64}\.(type)` only, so a reference cannot name any other file.
- Disk: `config/agreement.php`, `AGREEMENT_IMAGE_DISK` (default `local` = `storage/app/private`; deploys exclude `storage/` from rsync, so files persist). Moving to S3 later means setting the env and copying `agreement-images/`.
- `php artisan agreements:move-images-to-disk [--dry-run]` converts existing rows:
  - It writes the column straight to the table, so `updated_at` is not touched.
  - It rewrites a row only when the row reads back identically, comparing the read form so a row already partly converted is handled.
  - It is safe to re-run.

Local result (`fch-migration`):

| | Before | After |
|---|---|---|
| `agreement_signs` content (18 rows) | 31 MB total, avg 1.8 MB, max 6.2 MB | 1.1 MB total, avg 62 KB, max 135 KB |
| `agreement_templates` content (6 rows) | ~6 MB | 0.8 MB |

The images now sit on disk as 14 files, 16 MB in total. The two tables were backed up before the local run.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementImageStore.php`: new; extract/inline images.
- `app/Casts/AgreementImages.php`: new cast.
- `app/Models/Common/AgreementTemplate.php`, `app/Models/Common/AgreementSign.php`: columns use the cast.
- `app/Console/Commands/MoveAgreementImagesToDisk.php`: new one-off command.
- `config/agreement.php`: new; image disk and directory.
- `.env.example`: `AGREEMENT_IMAGE_DISK=local`.

## Tests
- Added: `tests/Unit/Agreement/AgreementImageStoreTest.php` (8). Covers: moved once per distinct image; exact round trip; every common type including HEIC; leaves alone what can't round-trip; a missing file is left as a reference; no path traversal.
- Added: `tests/Feature/Agreement/AgreementImageStorageTest.php` (4). Covers: template save/show; the template a borrower is asked to sign; signed HTML stored small and read back identical; PDF download still works.
- Added: `tests/Feature/Agreement/MoveAgreementImagesToDiskTest.php` (4). Covers: converts old rows without touching `updated_at`; re-run changes nothing; a partly converted row; dry run.
- Suites run: `tests/Unit/Agreement` + `tests/Feature/Agreement`: 40 passed. Full suite: 3162 passed, 20 skipped, 0 failed.

## Open / follow-up
- **Deploy:** run `php artisan agreements:move-images-to-disk --dry-run`, then without `--dry-run`, on stage and production after deploying. Back up both tables first.
- **Response size unchanged:** the API still sends the images inline. Only database size and row-read memory dropped.
- **HEIC signatures:** some signatures are HEIC phone photos, which Chrome cannot display, so the host team can't see those signatures. A drawn/typed signature (planned) would fix this; until then, consider converting or rejecting HEIC uploads.
- **Not done here (planned next, step 1 of the agreement plan):**
  - template versions, so every signature points at an exact version;
  - signing with field values only, with the server building the signed record, hash, IP and user agent (today the signer's browser still sends the full `content_html`, which can be altered);
  - sanitizing template HTML and sandboxing the viewer iframes (stored-XSS risk);
  - a server-rendered PDF.
- Signatures are not resized or compressed yet.
