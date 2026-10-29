# Document types say which categories are required

**Date:** 2026-10-29
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
The admin panel's client Documents tab now groups files by category and marks the required ones. The four required categories are defined here, in `BorrowerDocType::REQUIRED_NAMES`, but `common/borrower-document-types` didn't say which they are. The panel would have had to keep a second copy of the list.

## Solution
`Global\Document\BorrowerDocTypeResource` adds `is_required` (`BorrowerDocType::isRequiredName($name)`), so the rule stays in one place. The same resource serves `common/doc-types` and the borrower portal's type list. Adding a field breaks neither.

## Files changed
- `app/Http/Resources/Global/Document/BorrowerDocTypeResource.php`: `is_required`.

## Tests
- Added: `tests/Feature/Borrower/BorrowerDocumentsApiTest.php::the_document_types_say_which_categories_are_required`. Red first.
- Suites run: `BorrowerDocumentsApiTest`, `BorrowerPortalDocumentsTest`: 29 passed.

## Open / follow-up
- None.
