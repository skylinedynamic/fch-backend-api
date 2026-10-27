# Walkthrough: only four documents are required

**Date:** 2026-10-27
**Branch:** feature/new-walk-through
**Author:** Awlad Hossain

## Problem
`OnboardingStepService::hasUploadedDocuments()` held the borrower on the `upload_documents` step until every document category except Others and Miscellaneous Documents had a non-rejected document. That was nine of the eleven seeded categories. Only Driver's License, Voided Business Check, EIN / Corporation Docs and Business Bank Statements should be required.

## Solution
`BorrowerDocType` now lists the required categories instead of the optional ones:
- Constants for the four names, collected in `REQUIRED_NAMES`. The old `OPTIONAL_NAMES` is gone; it had no other users.
- `scopeRequired()` matches those names in SQL, ignoring case and surrounding spaces, and treats straight and curly apostrophes as the same (the seeder writes "Driver’s License"). Checked read-only against the local MySQL database: it returns exactly the four.
- `isRequiredName()` does the same match in PHP.

The fallback is unchanged: with none of the four set up, any document completes the step. The borrower portal keeps the same list (`src/config/documents.ts`).

## Files changed
- `app/Models/Borrower/BorrowerDocType.php`: `REQUIRED_NAMES`, `isRequiredName()`, new `scopeRequired()`
- `app/Services/Borrower/Profile/OnboardingStepService.php`: docblocks name the four

## Tests
- Added: `tests/Unit/Borrower/BorrowerDocTypeRequiredNamesTest.php`: the four, apostrophe and case variants, every other seeded name optional
- Added: `tests/Integration/Borrower/BorrowerDocTypeRequiredScopeTest.php`: the scope over the seeded names, case and apostrophe variants, soft-deleted categories
- Updated: `tests/Feature/Profile/OnboardingStepTest.php`: fixtures use the seeded names. New tests: "only the four required categories are needed" and "documents in optional categories do not stand in for required ones".
- Run: the three files, 79/79 passed (watched them fail first). Full suite: 3095 passed, 2 skipped, 1 failed. The failure is unrelated: `Tests\Unit\Assistant\NavigationMapTest` (`borrower_support:capital_access -> /capital-access`), because the portal renamed that route to `/credit-card-stacking` earlier on this branch.

## Also found
- The support assistant's navigation map still points to `/capital-access`. It needs updating to `/credit-card-stacking` (see the NavigationMapTest failure above). Not fixed here.

## Open / follow-up
- Matching is by name. Renaming one of the four in the admin panel makes it optional without warning. An `is_required` column would avoid that.
