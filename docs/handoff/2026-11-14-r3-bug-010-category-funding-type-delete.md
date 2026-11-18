# R3-BUG-010: a product category that is a sequence's Funding Type can't be deleted

Date: 2026-11-14

## Problem

Host Admin › Lenders › Manage Product Categories could delete a category that a funding sequence has as its **Funding Type**.

QA's steps:
1. Created category 38.
2. Created sequence 22 with Funding Type = 38. No lender rule used it.
3. Ran `DELETE admin/lenders/product-categories/38`.

The result was 200 "Category deleted successfully". Sequence 22 kept `funding_type_id` 38, but its type was now null (the category is soft-deleted).

`LenderService::deleteProductCategory()` only looked for the category in sequences' lender rules (`lender_visibilities.lender_product_category_id`). It never checked a sequence's own Funding Type (`funding_sequencings.funding_type_id`). No foreign key guards it: it was dropped in July, and the category is soft-deleted anyway.

## Solution

`deleteProductCategory()` now counts the live funding sequences that use the category **either way**:
- in their lender rules, or
- as their Funding Type.

Each sequence is counted once. If there is any, it throws `LenderProductCategoryInUseException` as before. The response is 422 `lender.category_in_use` with `errors.blocked: [{id, name, count}]`, and nothing is deleted. A deleted sequence does not count.

`productCategoriesWithUsage()` (the list's `funding_sequences_count`) is unchanged, as agreed: it still counts lender rules only. For a category that is only a Funding Type, the admin modal asks "Are you sure?", then shows the API's refusal in its existing "in use" modal.

## Files changed

- `app/Services/FCH/Admin/Lender/LenderService.php`: `deleteProductCategory()` also counts sequences whose `funding_type_id` is the category, each sequence once.
- `tests/Feature/Funding/FundingCatalogueAdminApiTest.php` (+3):
  - `a_product_category_that_is_a_sequences_funding_type_is_not_deleted`: 422, the category is kept, and the sequence still has its type.
  - `a_sequence_using_the_category_both_ways_counts_once`: one sequence using it both ways plus one using it as Funding Type only gives a count of 2.
  - `a_deleted_sequences_funding_type_can_be_deleted`

## Testing

- **Seen failing first:** the first two new tests. The third passed before too; it guards that a deleted sequence is not counted.
- **`tests/Feature/Funding`, `tests/Feature/Lender`, `FundingTypeOptionsTest`:** 108 passed.
- **Full suite:** 5,530 tests, all passed (2 skipped).

## Open items

- **List count:** the category list's `funding_sequences_count` still ignores Funding Type use. So the admin modal only refuses after "Are you sure?", not at once.
- **Modal wording:** the admin's refusal text ends "Remove it from their Underwriting Logic first.", which doesn't fit a Funding Type use. The wording is in `Fch-admin-frontend-react/src/pages/lenders/components/LenderProductCategoryModal.tsx`.
- **Existing data:** sequences already pointing at a deleted category (like QA's, already cleaned up) aren't repaired. Check with `FundingSequencing::whereNotNull('funding_type_id')->whereDoesntHave('type')`.
