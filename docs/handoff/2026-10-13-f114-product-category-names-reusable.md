# Deleted product category names reusable (F114, was O32)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F114 (was O32)

## Problem
`lender_product_categories` is soft-deleted, but its `name` column had a **database unique index**. Validation (`unique:lender_product_categories,name`) counted deleted rows too.

Once a category was deleted, its row kept the name taken for good. Creating a category with that name again failed with "already taken", although nothing showed in the list and there is no way to restore a deleted category.

## Solution
**Decision (user):** deleted categories should be ignored, since they cannot be restored.

- **Migration `2026_10_13_000100_drop_unique_name_on_lender_product_categories`:** drops the unique index on `name` and adds a plain index, so name lookups stay fast. `down()` puts the unique index back.
- **Validation:** `StoreLenderProductCategoryRequest` and `UpdateLenderProductCategoryRequest` use `Rule::unique('lender_product_categories', 'name')->withoutTrashed()` (update also uses `ignore($id)`). A name is unique among categories that are not deleted, and a deleted name is free again.
- **Why not a unique `(name, deleted_at)` index:** in MySQL, NULLs never collide in a unique index. Live categories would be able to share a name. Uniqueness among live rows is therefore enforced by validation. This is acceptable for an admin-only screen.
- Funding groups and funding types already worked this way. Categories now match them.

## Files changed
- `database/migrations/2026_10_13_000100_drop_unique_name_on_lender_product_categories.php` (new)
- `app/Http/Requests/Api/V1/FCH/Admin/Lender/StoreLenderProductCategoryRequest.php`
- `app/Http/Requests/Api/V1/FCH/Admin/Lender/UpdateLenderProductCategoryRequest.php`

## Tests
All in `Feature/Funding/FundingCatalogueAdminApiTest`:
- `a_deleted_name_can_be_used_again`: product categories added to the URLs it checks. They had been left out because of O32.
- `a_live_product_category_name_stays_unique`: a live name is still refused on create and on rename, and a deleted category's name can be taken by a rename.
- Both failed first, then passed.

Suites run: fch-api full suite: 2292 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- Run `php artisan migrate` on deploy.
- Two admins creating the same name at the same instant could both succeed, because there is no database constraint. This is acceptable for this screen.
