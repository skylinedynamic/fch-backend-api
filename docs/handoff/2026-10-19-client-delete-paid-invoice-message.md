# Client delete: clear reason when a paid invoice blocks it, bulk and subscription deletes fixed

**Date:** 2026-10-19
**Branch:** `fix/client-delete-paid-invoice-message` (from `release`) - same branch name in `Fch-admin-frontend-react`
**Author:** arafat-invento

## Problem
1. Deleting a client with a paid invoice showed only "Failed to delete borrower(s)." The model refused with a reason, but `BorrowerService::delete()` caught it and replaced it, so the user did not know why or what to do.
2. "Paid" was checked as `Paid` only, while the delete itself kept `Success` invoices as paid, so a client whose payment was recorded as `Success` was deleted.
3. Selecting two or more clients and deleting failed with 400 on stage and local: the delete lazy-loaded `Borrower->user`, refused by `Model::preventLazyLoading(!production)` once more than one model is loaded.
4. Deleting **any** client with a subscription failed: the delete called `Subscription::allPhases()`, removed with the subscription_phases table (dropped 2026-08-31).

## Solution
- Paid = `Borrower::PAID_INVOICE_STATUSES` (`Paid`, `Success`).
- `BorrowerService::delete()` checks the whole selection first. If any client has a paid invoice it answers **422** and deletes nothing:
  - one client selected: "This client has related records and cannot be deleted. Please change the client's status instead."
  - several selected: "Some selected clients have related records and cannot be deleted: <names>. Please change their status instead, or unselect them to delete the others."
  - The wording names no invoice detail on purpose (product decision 2026-10-19).
  - `errors.blocked`: `[{id, name}]` of the blocked clients. `check-deletable` returns the same reason per client.
- A client with only unpaid invoices is deleted, with those invoices and the subscription (soft-deleted), so nothing is left to pay.
- `user` is loaded with `loadMissing()` / `with()`; the `allPhases()` call is gone.
- Admin portal (`Fch-admin-frontend-react`, same branch): Delete Selected first calls `check-deletable`. A blocked client opens a "Client Cannot Be Deleted" flip modal (cross, Close, Go to Client) instead of "Are you sure?". A 422 from the delete opens the same modal instead of an error toast.

## Files changed
- `app/Models/Borrower/Borrower.php` — `PAID_INVOICE_STATUSES`, `notDeletableReason()`, `loadMissing('user')`, subscriptions deleted without `allPhases()`
- `app/Services/FCH/Common/Borrower/BorrowerService.php` — pre-check, 422 with message and `errors.blocked`, `with('user')`, check-deletable reason
- `lang/en/borrower.php` — `delete_blocked_paid_invoice`, `delete_blocked_paid_invoice_named`

## Tests
- `tests/Feature/Borrower/BorrowerRecordsApiTest.php`: paid invoice blocks with the message and `errors.blocked`; a multi-select names each blocked client and deletes none; Success counts as paid; check-deletable gives the same reason; unpaid invoices are deleted with the client; a client with a subscription is deleted; bulk delete of two clients with logins; host admin same rule.
- Suites run: full `php artisan test` — 2839 passed, 2 skipped, 0 failed. Frontend `npx vitest run` — 806 passed.

## Lead delete (same branch)
- **Problem:** on `release` the admin portal deleted a lead with `DELETE /common/leads/{id}`, a route the API removed (F108), so every lead delete answered 405 and nothing was deleted. A lead already converted to a client or partner could also be deleted.
- **Solution:** the admin portal now uses `POST /common/leads/multiple-delete` (ported from `stage`, commit `45d22b24`). `LeadService::deleteMultiple()` refuses a selection holding a converted lead (`convert_to` set): 422, "This lead has already been converted and cannot be deleted." (several: names them), `errors.converted` [{id, name}], nothing deleted or changed - not the lead, not the client/partner made from it. Other leads delete as before (soft delete, activity log).
- **Files:** `app/Services/FCH/Common/Lead/LeadService.php`, `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`, `app/Exceptions/LeadConvertedException.php`, `lang/en/lead.php`
- **Tests:** `tests/Feature/Lead/LeadAndContactApiTest.php` - a converted lead is kept and the borrower untouched; a mixed selection names the converted ones and deletes none.

## Partner delete (same branch, F128)
- **Problem:** a partner whose payment was `Success` was deleted (only `Paid` counted); a blocked partner got "Could not delete the selected partner(s). Please try again." with no reason; deleting 2+ partners failed on stage/local (lazy load); any partner with a subscription failed (`allPhases()`); the image delete got the URL instead of the stored path.
- **Solution:** same rule as clients. `PartnerService::delete()` checks the selection first: a paid invoice (Paid/Success), or clients/users still under it (F105 kept - otherwise deleting a partner would delete its clients), -> 422 "This partner has related records and cannot be deleted. Please change the partner's status instead." (several: names each), `errors.blocked` [{id, name}], nothing deleted. check-deletable gives the same reason. A partner with only unpaid invoices is deleted with them and its subscriptions. Relations queried instead of lazy-loaded; `allPhases()` gone; image removed after commit by its stored path.
- **Files:** `app/Models/Partner/Partner.php`, `app/Services/FCH/Partner/PartnerService.php`, `lang/en/partner.php` (`delete_blocked`, `delete_blocked_named`; `has_borrowers_or_users` removed).
- **Tests:** `tests/Feature/Partner/PartnerAdminApiTest.php` - paid invoice blocks with message and `errors.blocked`; mixed selection names each and deletes none; Success counts as paid; check-deletable same reason; unpaid invoices go with the partner; bulk delete of two partners with logins; partner with a subscription deleted; borrowers/users tests now expect 422 and the new message.

## Lender delete (same branch, F129)
- **Problem:** a lender set as `assign_lender` on a funding request could be deleted. The admin portal deleted a selection with one DELETE per lender at once, so a refused lender left the others deleted.
- **Solution:** `Lender::isDeletable()` - not `assign_lender` on any (non-deleted) funding request and no funding-sequence visibility rules. Being only in `lender_ids` (matched, not assigned) does not block. New `POST /admin/lenders/multiple-delete` (all or none) and `POST /admin/lenders/check-deletable` (`DeleteLendersRequest`). Blocked -> 422 "This lender has related records and cannot be deleted. Please change the lender's status instead." (several: names each), `errors.blocked`. `DELETE /admin/lenders/{id}` uses the same rule and message (the old visibility-rule message is replaced).
- **Files:** `app/Models/Lender/Lender.php`, `app/Services/FCH/Admin/Lender/LenderService.php`, `app/Http/Controllers/Api/V1/FCH/Admin/Lender/LenderController.php`, `app/Http/Requests/Api/V1/FCH/Admin/Lender/DeleteLendersRequest.php`, `app/Exceptions/LenderInUseException.php`, `routes/admin.php`, `lang/en/lender.php`.
- **Tests:** `tests/Feature/Funding/FundingCatalogueAdminApiTest.php` - assigned lender kept (check, bulk, single delete); mixed selection names each and deletes none; a lender only matched is deleted; two lenders deleted in one call; ids validated; visibility-rule test now checks the message.
- Suites run: full `php artisan test` - 2853 passed, 2 skipped. Admin portal `npx vitest run` - 833 passed.
## Funding sequence delete (same branch, F130)
- **Problem:** a funding sequence used by a funding request (`funding_sequence_id`) could be deleted. The portal deleted a selection one DELETE per sequence at once, so a refused one left the others deleted. A deleted sequence left its lender visibility rules, which kept that lender undeletable forever.
- **Solution:** `FundingSequencing::isDeletable()` - no non-deleted funding request uses it. New `POST /admin/funding-sequencings/multiple-delete` (all or none) and `POST /admin/funding-sequencings/check-deletable` (`DeleteFundingSequencingsRequest`). Blocked -> 422 "This funding sequence has related records and cannot be deleted. Please change the funding sequence's status instead." (several: names each), `errors.blocked`. `DELETE /admin/funding-sequencings/{id}` same rule. `Lender::isDeletable()` now counts only visibility rules of a live sequence. Soft delete, as before.
- **Files:** `app/Models/Funding/FundingSequencing.php`, `app/Services/FCH/Admin/Funding/FundingSequencingService.php`, `app/Http/Controllers/Api/V1/FCH/Admin/Funding/FundingSequencingController.php`, `app/Http/Requests/Api/V1/FCH/Admin/Funding/DeleteFundingSequencingsRequest.php`, `app/Exceptions/FundingSequenceInUseException.php`, `app/Models/Lender/Lender.php`, `routes/admin.php`, `lang/en/funding_sequencing.php`.
- **Tests:** `tests/Feature/Funding/FundingCatalogueAdminApiTest.php` - used sequence kept (check, bulk, single); mixed selection names each and deletes none; a sequence whose only request was deleted can go; two deleted in one call; ids validated; a lender whose only sequence was deleted can be deleted.
- Only funding requests count. `funding_applications.funding_sequence_id` is not checked (not asked for).
- Suites run: full `php artisan test` - 2861 passed, 2 skipped. Admin portal `npx vitest run` - 841 passed.

## Open / follow-up
- O59: deleting a client also deletes unpaid commission-payout invoices stamped with that client's `borrower_id`. Needs a decision.
- O62 fixed: `User::safeDeleteWithRelations()` still called `allPhases()`, so a partner or client with its own login and a subscription passed the check, then failed to delete ("Could not delete the selected partner(s). Please try again."). Removed; tests `a_partner_with_its_own_login_a_subscription_and_only_unpaid_invoices_is_deleted`, `a_client_with_a_login_and_a_subscription_is_deleted`.
- O60, O61: partners fixed (F128); still open for **user** delete.
- Lender routes have no per-permission gate (only the admin role gate), as before.
- Deploy the API and the admin portal together; merge into `stage` as well as `release` (hotfix flow).
