# Lender phone not unique (F113, was O31)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F113 (was O31)

## Problem
The two lender requests disagreed about `contact_phone`:
- `StoreLenderRequest` took any phone.
- `UpdateLenderRequest` required it to be unique, using `Rule::unique('lenders', 'contact_phone')->ignore($id)`. That rule also counted soft-deleted lenders.

So two lenders could be created with one phone number, and then **neither could be edited**. Every save returned 422 on the phone, even when the phone was not being changed. A deleted lender's phone blocked edits in the same way.

## Solution
**Decision (user):** a phone number does not need to be unique. Lenders often share a support line.

- The unique rule and its message were removed from `UpdateLenderRequest`.
- Create was already non-unique and is unchanged.
- There is no database index on the column, so no migration is needed.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Admin/Lender/UpdateLenderRequest.php`

## Tests
- `Feature/Funding/FundingCatalogueAdminApiTest::lenders_can_share_a_phone_number_and_still_be_edited`: two lenders on one phone are created and both are edited. It failed first with 422 on edit, then passed.
- Suites run: fch-api full suite: 2292 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
`lang/en/lender.php` still has `contact_phone_unique`, which is no longer used.
