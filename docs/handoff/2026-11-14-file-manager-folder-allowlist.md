# R3-BUG-002: the file manager opens only the folders on its list

Date: 2026-11-14

## Problem

Every file manager call (`/api/v1/filemanager/{list,tree,upload,folder,rename,delete,paste,download}`) names its own root folder: `{entity_type}/{entity_id}`, or the whole `{entity_type}` with `get_all`.

`FileManagerController::partnerMayOpen()` checked only `borrowers`, `employees` and `partner-documents`. It returned `true` for every other folder. So any partner user could do all of the following, with a direct API call:
- open another partner's folder, `partners/{id}`, including its logo and branding
- open the host's CMS, vendor, credit card and Resources folders, and upload to, rename or delete in them
- open the folders that features write themselves: `credit-reports`, `funding-application`, `agreement-images`, `attachments` and `embed_forms`, holding credit reports and application documents
- name the root as a path (for example `borrowers/../credit-reports`) to reach a folder some other way
- upload a PDF or another document into a public folder, where `/storage/...` serves it to anyone with the link

## Solution

**One list of folders** (`App\Support\Files\FileManagerFolders`). A folder not on it is refused for everyone, the host included.

| Folder | Disk | Host's team | Another partner's team | Borrower |
|---|---|---|---|---|
| `borrowers/{id}` | private | any | its own partner's clients | own only |
| `employees/{id}` | public | any | its own partner's staff | – |
| `partners/{id}` | public | any | its own partner only | – |
| `partner-documents/{id}` | private | ✓ | – | – |
| `users/{id}` | public | ✓ | – | – |
| `resources` | private | ✓ | – | – |
| `vendors`, `blogs`, `seo-settings`, `site-settings`, `credit-accounts` | public | ✓ (also whole) | – | – |
| anything else (`audit`, `credit-reports`, `funding-application`, `agreement-images`, `attachments`, `embed_forms`, `contacts`, `resource-providers`, `agreements`, …) | – | – | – | – |

The host-only folders follow the API's permissions: creating and updating resources, CMS content, credit accounts and tiers are host-only permissions.

**Opening a whole folder (`get_all`).**
- Only the five shared libraries can be opened whole: the vendor logos, blog, SEO, site and credit card pictures. These screens open them that way, and only the host's team may.
- A per-record folder is never opened whole, by the host either. Opening `borrowers` whole would list every partner's clients at once.

**The root is matched exactly.**
- `entity_type` must be one of the listed names, so `borrowers/../credit-reports`, `./borrowers`, `Borrowers` and backslash forms are refused.
- `entity_id` must be a whole number, so `5/../..`, `-1`, `abc` and `1e3` are refused.
- A request that names no root at all still gets its 422 from validation.

**Public folders take pictures only.**
- `UploadFileRequest` uses `SafeUpload::image()` for a folder not in `config/files.php` `private_folders`. That means JPG, PNG, GIF, WebP and ICO, plus SVG with its scripts stripped.
- Private folders keep `SafeUpload::document()`.
- The screens that open public folders (branding, site settings, profile and staff pictures, vendor, blog, SEO and credit card pictures) already ask for pictures only.

**The admin panel's Agreement Builder** opened `agreements/{partner}`, which is now refused. It used `agreements/1` when the user had no partner. It now opens the partner's own `partners/{id}` folder at its base; nothing is created inside it.

**The admin panel's create pages** (`/employees/create`, `/partners/create`) opened the picture picker on the next id, a record that does not exist yet. A partner's team was refused there. The picker now waits until the record is saved. See the admin handoff `2026-11-14-picture-picker-waits-for-save.md`. See the admin handoff of the same date.

## Files changed

- `app/Support/Files/FileManagerFolders.php` (new): the list, who opens each folder, the shared libraries, and `isPublic()`.
- `app/Support/Files/Folder.php`: `USERS`, `VENDORS`.
- `app/Http/Controllers/Api/V1/Global/FileManager/FileManagerController.php`:
  - the middleware checks every root against the list
  - `recordId()` accepts whole numbers only
  - the separate audit check is folded into the list
- `app/Http/Requests/Api/V1/Global/FileManager/UploadFileRequest.php`: pictures only in a public folder. The `credit-accounts` literal is now `Folder::CREDIT_ACCOUNTS`.
- Tests:
  - `tests/Feature/FileManager/FileManagerApiTest.php`:
    - The general tests moved from `partners/7` (not the caller's) to the caller's own client folder.
    - +6 tests: the host's folders are host-only; only a shared library opens whole; a partner's folder is for its own team and the host; any other folder is refused for everyone; a root written as a path is refused; public folders take pictures only.
  - `tests/Feature/Upload/UploadSizeAndProfilePictureTest.php`: the 20 MB document test uploads to a private folder, because a public one now refuses it by type before size.

## Testing

- **Written first and seen failing:** the 6 new tests.
- **FileManager, Upload, Files and Audit suites:** 147 tests, all passed.
- **Full suite** (with the audit work and `audit:organize-files`): 5,511 tests, all passed (2 skipped).

## Open items

- **Server step:** none. The admin panel change must be deployed with this one, or the Agreement Builder's logo picker will be refused.
- **Already in public folders:** files uploaded there before this change (any PDF in `partners/` or `employees/`) are still served. Consider listing non-pictures in public folders and moving or removing them.
- **Permissions (phase 2):** the list checks host or partner and ownership, not the user's permissions. For example, a host staff member without "update blogs" can still open `blogs`.
- **Old `agreements/` folder:** it is empty locally. Remove it on the server once checked.
