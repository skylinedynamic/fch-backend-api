# Delete rules: leads, clients, partners, lenders and funding sequences

**Date:** 2026-10-19
**Branch:** `fix/client-delete-paid-invoice-message` (from `release`) - same branch name in `Fch-admin-frontend-react`
**Author:** arafat-invento

## Summary
One rule for all five deletes: a record still in use is kept, the whole selection is refused (all or none), the API answers 422 with a plain message and `errors.blocked` (`errors.converted` for leads), and the admin portal shows a "... Cannot Be Deleted" modal instead of "Are you sure?". Deleted records are soft-deleted (a partner's roles are hard-deleted, as before).

| Record | Kept when | Deleted when | Findings |
|---|---|---|---|
| Lead | converted (`convert_to` set) | not converted | F127 |
| Client | a Paid or Success invoice | only unpaid invoices (they go with it) | F124-F126 |
| Partner | a Paid or Success invoice, or clients/users under it | only unpaid invoices, nothing under it | F128 |
| Lender | `assign_lender` on a funding request, or rules in a live funding sequence | otherwise (being only in `lender_ids` does not keep it) | F129 |
| Funding sequence | used by a (non-deleted) funding request | otherwise | F130 |

Also fixed: `User::safeDeleteWithRelations()` called the removed `Subscription::allPhases()` (O62), so any partner or client with its own login and a subscription could not be deleted.

## Client delete

### Problem
1. Deleting a client with a paid invoice showed only "Failed to delete borrower(s)." The model refused with a reason, but `BorrowerService::delete()` caught it and replaced it, so the user did not know why or what to do.
2. "Paid" was checked as `Paid` only, while the delete itself kept `Success` invoices as paid, so a client whose payment was recorded as `Success` was deleted.
3. Selecting two or more clients and deleting failed with 400 on stage and local: the delete lazy-loaded `Borrower->user`, refused by `Model::preventLazyLoading(!production)` once more than one model is loaded.
4. Deleting **any** client with a subscription failed: the delete called `Subscription::allPhases()`, removed with the subscription_phases table (dropped 2026-08-31).

### Solution
- Paid = `Borrower::PAID_INVOICE_STATUSES` (`Paid`, `Success`).
- `BorrowerService::delete()` checks the whole selection first. If any client has a paid invoice it answers **422** and deletes nothing:
  - one client selected: "This client has related records and cannot be deleted. Please change the client's status instead."
  - several selected: "Some selected clients have related records and cannot be deleted: <names>. Please change their status instead, or unselect them to delete the others."
  - The wording names no invoice detail on purpose (product decision 2026-10-19).
  - `errors.blocked`: `[{id, name}]` of the blocked clients. `check-deletable` returns the same reason per client.
- A client with only unpaid invoices is deleted, with those invoices and the subscription (soft-deleted), so nothing is left to pay.
- `user` is loaded with `loadMissing()` / `with()`; the `allPhases()` call is gone.
- Admin portal (`Fch-admin-frontend-react`, same branch): Delete Selected first calls `check-deletable`. A blocked client opens a "Client Cannot Be Deleted" flip modal (cross, Close, Go to Client) instead of "Are you sure?". A 422 from the delete opens the same modal instead of an error toast.

### Files changed
- `app/Models/Borrower/Borrower.php` — `PAID_INVOICE_STATUSES`, `notDeletableReason()`, `loadMissing('user')`, subscriptions deleted without `allPhases()`
- `app/Services/FCH/Common/Borrower/BorrowerService.php` — pre-check, 422 with message and `errors.blocked`, `with('user')`, check-deletable reason
- `app/Models/User/User.php` — subscriptions deleted without `allPhases()` (O62; used when a client's or partner's login is deleted)
- `lang/en/borrower.php` — `delete_blocked_paid_invoice`, `delete_blocked_paid_invoice_named`

### Tests
- `tests/Feature/Borrower/BorrowerRecordsApiTest.php`: paid invoice blocks with the message and `errors.blocked`; a multi-select names each blocked client and deletes none; Success counts as paid; check-deletable gives the same reason; unpaid invoices are deleted with the client; a client with a subscription is deleted; a client with a login and a subscription is deleted (O62); bulk delete of two clients with logins; host admin same rule.

## Lead delete (same branch)
- **Problem:** on `release` the admin portal deleted a lead with `DELETE /common/leads/{id}`, a route the API removed (F108), so every lead delete answered 405 and nothing was deleted. A lead already converted to a client or partner could also be deleted.
- **Solution:** the admin portal now uses `POST /common/leads/multiple-delete` (ported from `stage`, commit `45d22b24`). `LeadService::deleteMultiple()` refuses a selection holding a converted lead (`convert_to` set): 422, "This lead has already been converted and cannot be deleted." (several: names them), `errors.converted` [{id, name}], nothing deleted or changed - not the lead, not the client/partner made from it. Other leads delete as before (soft delete, activity log).
- **Files:** `app/Services/FCH/Common/Lead/LeadService.php`, `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`, `app/Exceptions/LeadConvertedException.php`, `lang/en/lead.php`
- **Tests:** `tests/Feature/Lead/LeadAndContactApiTest.php` - a converted lead is kept and the borrower untouched; a mixed selection names the converted ones and deletes none; several leads deleted in one call; a bulk delete never touches another partner's leads; ids validated.

## Partner delete (same branch, F128)
- **Problem:** a partner whose payment was `Success` was deleted (only `Paid` counted); a blocked partner got "Could not delete the selected partner(s). Please try again." with no reason; deleting 2+ partners failed on stage/local (lazy load); any partner with a subscription failed (`allPhases()`); the image delete got the URL instead of the stored path.
- **Solution:** same rule as clients. `PartnerService::delete()` checks the selection first: a paid invoice (Paid/Success), or clients/users still under it (F105 kept - otherwise deleting a partner would delete its clients), -> 422 "This partner has related records and cannot be deleted. Please change the partner's status instead." (several: names each), `errors.blocked` [{id, name}], nothing deleted. check-deletable gives the same reason. A partner with only unpaid invoices is deleted with them and its subscriptions. Relations queried instead of lazy-loaded; `allPhases()` gone; image removed after commit by its stored path.
- **Files:** `app/Models/Partner/Partner.php`, `app/Services/FCH/Partner/PartnerService.php`, `lang/en/partner.php` (`delete_blocked`, `delete_blocked_named`; `has_borrowers_or_users` removed).
- **Tests:** `tests/Feature/Partner/PartnerAdminApiTest.php` - paid invoice blocks with message and `errors.blocked`; mixed selection names each and deletes none; Success counts as paid; check-deletable same reason; unpaid invoices go with the partner; bulk delete of two partners with logins; partner with a subscription deleted; a new partner with its own login, a subscription and only unpaid invoices is deleted (O62 - the case seen on local); borrowers/users tests now expect 422 and the new message.

## Lender delete (same branch, F129)
- **Problem:** a lender set as `assign_lender` on a funding request could be deleted. The admin portal deleted a selection with one DELETE per lender at once, so a refused lender left the others deleted.
- **Solution:** `Lender::isDeletable()` - not `assign_lender` on any (non-deleted) funding request and no funding-sequence visibility rules. Being only in `lender_ids` (matched, not assigned) does not block. New `POST /admin/lenders/multiple-delete` (all or none) and `POST /admin/lenders/check-deletable` (`DeleteLendersRequest`). Blocked -> 422 "This lender has related records and cannot be deleted. Please change the lender's status instead." (several: names each), `errors.blocked`. `DELETE /admin/lenders/{id}` uses the same rule and message (the old visibility-rule message is replaced).
- **Files:** `app/Models/Lender/Lender.php`, `app/Services/FCH/Admin/Lender/LenderService.php`, `app/Http/Controllers/Api/V1/FCH/Admin/Lender/LenderController.php`, `app/Http/Requests/Api/V1/FCH/Admin/Lender/DeleteLendersRequest.php`, `app/Exceptions/LenderInUseException.php`, `routes/admin.php`, `lang/en/lender.php`.
- **Tests:** `tests/Feature/Funding/FundingCatalogueAdminApiTest.php` - assigned lender kept (check, bulk, single delete); mixed selection names each and deletes none; a lender only matched is deleted; two lenders deleted in one call; ids validated; visibility-rule test now checks the message.

## Funding sequence delete (same branch, F130)
- **Problem:** a funding sequence used by a funding request (`funding_sequence_id`) could be deleted. The portal deleted a selection one DELETE per sequence at once, so a refused one left the others deleted. A deleted sequence left its lender visibility rules, which kept that lender undeletable forever.
- **Solution:** `FundingSequencing::isDeletable()` - no non-deleted funding request uses it. New `POST /admin/funding-sequencings/multiple-delete` (all or none) and `POST /admin/funding-sequencings/check-deletable` (`DeleteFundingSequencingsRequest`). Blocked -> 422 "This funding sequence has related records and cannot be deleted. Please change the funding sequence's status instead." (several: names each), `errors.blocked`. `DELETE /admin/funding-sequencings/{id}` same rule. `Lender::isDeletable()` now counts only visibility rules of a live sequence. Soft delete, as before.
- **Files:** `app/Models/Funding/FundingSequencing.php`, `app/Services/FCH/Admin/Funding/FundingSequencingService.php`, `app/Http/Controllers/Api/V1/FCH/Admin/Funding/FundingSequencingController.php`, `app/Http/Requests/Api/V1/FCH/Admin/Funding/DeleteFundingSequencingsRequest.php`, `app/Exceptions/FundingSequenceInUseException.php`, `app/Models/Lender/Lender.php`, `routes/admin.php`, `lang/en/funding_sequencing.php`.
- **Tests:** `tests/Feature/Funding/FundingCatalogueAdminApiTest.php` - used sequence kept (check, bulk, single); mixed selection names each and deletes none; a sequence whose only request was deleted can go; two deleted in one call; ids validated; a lender whose only sequence was deleted can be deleted.
- Only funding requests count. `funding_applications.funding_sequence_id` is not checked (not asked for).

## Verification (final run, 2026-10-19)
| Check | Result |
|---|---|
| `php artisan test --testsuite=Unit` | 920 passed, 1 skipped (existing skip) |
| `php artisan test --testsuite=Integration` | 373 passed |
| `php artisan test --testsuite=Feature` | 1568 passed, 1 skipped (existing skip) |
| Admin portal `npx vitest run` | 841 passed (131 files) |
| Admin portal `tsc --noEmit` | 0 errors |
| `pint --test` on the new PHP files | passed (the changed existing files already fail Pint on `release`; not reformatted, to keep the diff small) |
| Manual, local browser | partner delete only (a new partner with an unpaid invoice was deleted with it) |

Tests run on SQLite; the rules are plain queries, but check once on stage (MySQL).

## Before merging
- **Do not commit `public/Four_Corner_Regression_Test_Cases_Round1 (2).xlsx`** - it is staged, and `public/` is served on the web. Unstage it: `git restore --staged "public/Four_Corner_Regression_Test_Cases_Round1 (2).xlsx"`.
- No new migrations.
- Dry-run merge (`git merge-tree`): the API merges cleanly into `stage` and `release`. The admin portal merges cleanly into `release`; into `stage` it conflicts in one test file only (`tests/pages/leads/Leads.bulkDelete.test.tsx`, also added on `stage`) - keep both sides' tests.
- Deploy the API and the admin portal together (the lender and funding pages call the new routes). Hotfix flow: merge into `stage` first for QA, then `release`.
- Not yet checked by hand in a browser: lead, client, lender and funding sequence delete.

## Open / follow-up
- O59: deleting a client also deletes unpaid commission-payout invoices stamped with that client's `borrower_id`. Needs a decision.
- O60, O61: partners fixed (F128); still open for **user** delete (bulk delete fails on stage/local; `Success` not counted as paid).
- Decisions asked, not answered yet: delete a partner with only unpaid invoices even when clients are under it (would delete those clients too)? Count `funding_applications` as using a funding sequence? A full purge for privacy requests (soft delete keeps PII)?
- Lender and funding-sequence routes have no per-permission gate (only the admin role gate), as before.
