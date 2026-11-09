# Uploads: the server checks file types — no SVG documents, sanitised SVG logos (BUG-015, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-015** (R2-CP-DOC-01, High/P1): a borrower uploaded through Documents an SVG holding `<script>console.log("svg-xss")</script>`. The upload returned 200, and opening `https://api.skylinedynamic.dev/storage/borrowers/144/1792561027_qa-r2-logo.svg` ran the script on the API's domain. QA saw `.exe` and `.html` refused and SVG listed as allowed.

The refusals came from the portals only (`src/modules/filemanager/config.ts`). The API checked no type at all: `UploadFileRequest` had `'file' => ['required', 'file']`, so a direct call could store HTML or SVG. Activity attachments (create in both portals, edit), the client's business logo and the CMS images listed SVG explicitly. Partner image/logo, contact and employee images and the site-settings logos had no type rule at all.

## Solution
- `config/uploads.php` — one place for what the server takes: `document_extensions` (the portals' list, plus ppt/pptx, **no SVG**), `image_extensions` (jpg, jpeg, png, gif, webp, ico, svg) and `script_mime_types` (HTML, XHTML, SVG, XML, JavaScript, PHP, shell).
- `App\Rules\SafeUpload` — checks the extension against the list **and** the content, read from the bytes with `finfo`, so HTML saved as `.jpg` is refused too. Values that are not uploads (stored paths, null) pass to the field's other rules.
  - `SafeUpload::document()` — documents, file manager uploads, activity attachments: never SVG, HTML or script.
  - `SafeUpload::image()` — logos and pictures: an SVG is accepted after `App\Support\Uploads\SvgSanitizer` strips `<script>`, `on*` handlers, `javascript:` links and remote references and writes the clean markup over the upload; an SVG that cannot be read is refused.
- Applied to: file manager upload; activity attachments (`StoreActivityLogRequest`, `StoreBorrowerActivityLogRequest`, `UpdateActivityLogRequest`, whose own lists also lose `svg`); `UpdateBorrowerRequest.business_logo`; CMS `BlogRequest`/`SeoRequest` images; partner `company_logo`/`partner_image` (store/update); contact and employee `image` (store/update); the ten site-settings logo/favicon fields.
- New dependency **`enshrined/svg-sanitize` ~1.0.0** (released 2026-09-01; 1.1.x was days old and left out).
- Messages in `lang/en/uploads.php`.
- Both portals drop `svg` from their file manager list, so the picker refuses it before the upload.

Files already stored (QA's test SVG among them) are not touched; they are still served from the public disk. Serving uploads through Laravel from a private disk is planned separately (BUG-016).

## Files changed
- `composer.json`, `composer.lock` — `enshrined/svg-sanitize` ~1.0.0 (lock: that package only).
- `config/uploads.php`, `lang/en/uploads.php`, `app/Rules/SafeUpload.php`, `app/Support/Uploads/SvgSanitizer.php` — new.
- 14 form requests under `app/Http/Requests/Api/V1/` — the rule added as listed above.
- `tests/Unit/Rules/SafeUploadTest.php`, `tests/Feature/Upload/UnsafeUploadsRefusedTest.php` — new.

## Testing
- Both new test files failed first (rule class missing; the file manager took the scripted SVG and HTML-as-.jpg with 200; an SVG activity attachment was accepted), then passed.
- Note: `UploadedFile::fake()->getMimeType()` answers from the file name, which is why the rule reads the content with `finfo` itself.
- Affected suites (FileManager, ActivityLog, Partner, Employee, Website, Borrower, Setting, Upload, rule) — OK, 641 tests.
- Full PHPUnit suite — OK, 4298 tests, 14320 assertions (2 skipped).

## Open items
- **QA's test file** `storage/app/public/borrowers/144/1792561027_qa-r2-logo.svg` on staging still runs if opened; delete it on the server (QA kept it for the retest, so retest first).
- Older SVGs uploaded as logos before this change are not sanitised.
- Private disk / served-through-Laravel uploads with `nosniff` + `attachment` + `sandbox` (BUG-016) — later.
- Profile picture (`UpdateProfilePictureRequest.image`) is a string field, not an upload; not changed.
- `composer require` on Windows needs `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`; the servers have both extensions.
