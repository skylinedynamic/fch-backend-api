# Tier tasks and vendors in use cannot be deleted; vendor logo is a File Manager path (BUG-095, reopened)

Date: 2026-10-29

## Problem
Owner › Credit Builder › Manage Tier. Follow-up to BUG-095 (`2026-10-25-tier-1-task-requires-its-vendor.md`):
- **Deleting a task or vendor clients already use broke their screens.** Both are soft-deleted, but nothing reads them `withTrashed`, so a client's records then pointed at nothing. These would fail on the missing task (found by reading the code, not run):
  - the client's Credit Guide (`CreditBuilderStepGuideService`)
  - their task overview (`TaskOverviewItemResource`)
  - the admin client's Credit Builder tab (`TierService`)
  - approving a pending verification for that task (`TaskVerificationService::tierUpdate`)
- **A deleted task could also distort tier progress.** It no longer counts towards the tier's task total, while the client's completed requests for it still count.
- **Deleting a vendor** left its tasks, and the client's Credit Guide, without a vendor.
- **The admin's "Delete Selected" for vendors** posted to `vendors-multiple-delete`, which did not exist.
- **The vendor logo** (shown on the client's Credit Guide) was a file upload. It was decided to choose it in the File Manager instead, like a credit account's image.

## Solution
- **A task in use is not deleted.** "In use" means a `borrower_task_verification_requests` or `migrated_tasks` row names it. `tier-tasks-multiple-delete` answers 422 with `errors.in_use: [{ id, name }]`, the tasks it kept, and nothing is deleted (`TierTaskService::inUse()`).
- **A vendor in use is not deleted.** "In use" means a tier task names it. `DELETE vendors/{id}` and the new `POST vendors-multiple-delete` answer 422 with `errors.in_use: [{ id, name }]`, and nothing is deleted (`CreditBuilderVendorService::inUse()`, `deleteMany()`). The admin shows these in a "cannot be deleted" dialog.
- **The vendor logo is a path.** `logo` is a path in the File Manager's storage (`vendors/uline.png`): an image extension only, no `..` and no URL. The service saves it as sent: `null` removes it, and leaving it out keeps the one on file. The service no longer stores uploads and no longer deletes files, since the File Manager owns them. The resource still sends `asset('storage/'.logo)`.
- **Tiers:** no change. Deleting a tier is not allowed by design. The admin offers it only to the Developer role.

## Files changed
- `app/Services/FCH/Admin/CreditBuilder/TierTaskService.php`: `inUse()`.
- `app/Http/Controllers/Api/V1/FCH/Admin/CreditBuilder/TierTaskController.php`: `multipleDelete()` refuses tasks in use.
- `app/Services/FCH/Admin/CreditBuilder/CreditBuilderVendorService.php`: logo saved as a path; `inUse()`, `deleteMany()`; `Storage` no longer used.
- `app/Http/Controllers/Api/V1/FCH/Admin/CreditBuilder/CreditBuilderVendorController.php`: `destroy()` refuses a vendor in use; new `multipleDelete()`.
- `app/Http/Requests/Api/V1/FCH/Admin/CreditBuilder/Vendors/CreditBuilderVendorRequest.php`: `logo` is an image path.
- `routes/admin.php`: `POST credit-builder/vendors-multiple-delete`.
- `lang/en/tier_task.php`: `error_in_use`. `lang/en/credit_builder.php`: `vendor_in_use`, `validation_vendor_logo`.

## Testing
- New `tests/Feature/CreditBuilder/TierTaskAndVendorInUseDeleteTest.php` (8):
  - a task with a client's verification, or migrated to a client, is not deleted
  - one task in use stops the whole bulk delete; an unused task is deleted
  - a vendor a task uses is not deleted; an unused one is
  - the vendor bulk delete refuses when one is in use, deletes otherwise, and needs ids
- New `tests/Feature/CreditBuilder/VendorLogoFromFileManagerTest.php` (4): the path is kept on create; edit changes it, and leaving it out keeps it; `null` removes it; a pdf, `..` or URL is refused.
- Both failed first (200 instead of 422, a 404 for the missing route, 422 on a logo path), then passed. `tests/Feature/CreditBuilder`: 119/119.
- Full suite: see the session report.

## Open items
- **Logos uploaded before this** sit in `storage/app/public/vendors/` and keep working, since their paths are unchanged. They may not appear in the File Manager's listing.
- **A task or vendor that is no longer wanted but is in use** can only be retired by status for now (vendors have `status`). Tasks have no "inactive" state.
- **Tasks already deleted on stage or production** while in use still point at nothing. Restoring them (`deleted_at = null`) is a data fix that was not done here.
