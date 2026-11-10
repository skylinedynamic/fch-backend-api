# Uploads: size limits, real-image check, profile picture checked (BUG-014, round 2)

2026-11-01

## Problem
QA round 2 defect log **BUG-014** (R2-OP-PROF-01, Medium/P2, status New). As host admin, QA went to Profile, clicked the avatar camera and chose Upload. They uploaded `qa-12mb.png`: 12 MB, the PNG signature followed by zeros. The form says "Maximum size: 1 MB". `POST /api/v1/filemanager/upload` answered 200. The file landed in the Partners folder with a broken thumbnail, and it could be set as the profile picture.

There were three gaps:
1. **No size limit on the server.** `SafeUpload` (BUG-015) checked the extension and refused script content only. PHP allows 2 GB, and only the portals capped uploads at 20 MB.
2. **A fake picture passed.** finfo reads only the first bytes and saw `image/png`. Nothing checked that the file opens as an image.
3. **The profile picture was any path.** `UpdateProfilePictureRequest` had `'image' => ['required', 'string']`, so any file-manager file (PDF, ZIP, the fake PNG) could be set.

## Solution
Limits agreed with Awlad: **pictures up to 5 MB, everything else up to 20 MB**.

- `config/uploads.php`:
  - `max_kilobytes.picture` (5 MB) and `max_kilobytes.document` (20 MB).
  - `picture_extensions`: which files count as pictures.
  - `decodable_picture_extensions` (jpg, jpeg, png, gif, webp, bmp): these must open with `getimagesizefromstring()`.
  - `profile_picture_extensions` (jpg, jpeg, png).
- `SafeUpload` (both `document()` and `image()`, so all 14 requests that use it):
  - refuses a file over its limit: `uploads.too_large`, "This file is too large. The maximum size is :size MB."
  - refuses a decodable picture that does not open: `uploads.invalid_image`.
  - exposes `maxKilobytesFor()` and `opensAsPicture()` for reuse.
- New rule `StoredProfilePicture`. The path must point to a JPG, JPEG or PNG that exists in the user's own folder, is within 5 MB, and opens as an image. Messages: `uploads.picture_type`, `uploads.picture_missing`, `too_large`, `invalid_image`.
  - `UpdateProfilePictureRequest` uses it together with `NoPathTraversal`.
  - The folder comes from the new `User::profilePictureFolder()` (`partners/{id}` or `employees/{id}`). `AuthProfileService::updateProfilePicture()` now uses the same method instead of its own string building.

## Files changed
- `config/uploads.php`: size limits and picture lists.
- `lang/en/uploads.php`: `too_large`, `invalid_image`, `picture_type`, `picture_missing`.
- `app/Rules/SafeUpload.php`: size check, picture check, two public helpers.
- `app/Rules/StoredProfilePicture.php`: new.
- `app/Models/User/User.php`: `profilePictureFolder()`.
- `app/Services/FCH/Common/AuthProfile/AuthProfileService.php`: uses `profilePictureFolder()`.
- `app/Http/Requests/Api/V1/FCH/Common/AuthProfile/UpdateProfilePictureRequest.php`: `NoPathTraversal` and `StoredProfilePicture`.
- `tests/Unit/Rules/SafeUploadTest.php`: four new cases (20 MB document, 5 MB picture, QA's 12 MB fake PNG, picture that does not open).
- `tests/Feature/Upload/UploadSizeAndProfilePictureTest.php`: new, 7 cases. Covers QA's upload, a small fake PNG, a document over 20 MB with a real image stored, a profile picture that is fake, a PDF or missing, a picture over 5 MB, a valid picture, and a path into another partner's folder.
- `tests/Feature/User/AuthProfileApiTest.php`: the profile-picture test now stores a real PNG first. It used a path to a file that did not exist.

## Testing
- New tests failed first: QA's upload returned 200 and every profile-picture path was accepted. They then passed.
- Upload, profile and file manager suites: 48/48.
- Full PHPUnit: OK, 4366 tests, 14500 assertions, 2 skipped.
- Admin live E2E `e2e/topbar/profile/profile-picture-upload-limits.live.spec.ts` and borrower E2E `e2e/sidebar/documents/document-upload-limits.spec.ts` ran against this API and passed.

## Open items
- **Server (Nginx/PHP):** `client_max_body_size` and PHP's `upload_max_filesize`/`post_max_size` must be at least 20 MB plus multipart overhead (25 MB suggested). This is not in the repo.
- Files uploaded before this change are not checked. QA deleted their test file. The partner and employee forms still save a picked `partner_image`/`image` path without `StoredProfilePicture`. New fake or oversized files can no longer get into the file manager, but `StoredProfilePicture` could be added there as well.
- Private disk for uploads (BUG-016) is still deferred.
