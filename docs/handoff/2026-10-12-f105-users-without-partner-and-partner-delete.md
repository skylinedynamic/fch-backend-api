# Users without a partner; partner delete (F105, was O6)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F105 (was O6)

## Problem
- `InvoiceService::applyRbacScope()` and the copy in `PaymentService` treated a user whose partner could not be found (`partner_id` null, or the partner deleted) as the host. That user saw every partner's invoices and payments. With no host partner on record, no filter was applied, so the user saw every invoice there was.
- Nothing else stopped such a user either. Login and every other route let them through.
- A partner could be deleted while borrowers and users were still under it. `safeDeleteWithRelations()` deleted them all along with it.
- `BorrowerAuthService::registerBorrower()` (borrower self-sign-up; its route is commented out today) created the login without `partner_id`.

## Solution
Rule: every user belongs to a partner. A user without one may do nothing.
- **New `EnsureUserHasPartner` middleware.**
  - Appended to the `api` group. It is placed after `AuthenticatesRequests` in the priority list, so it runs after the route's auth.
  - Any signed-in user whose partner is missing or soft-deleted gets 403 `auth.no_partner`.
  - Routes with no signed-in user are not affected.
- **Login.** `AuthService::login()` refuses such a user with 401 `auth.no_partner`, before any token is issued. This covers both the partner portal and the borrower portal.
- **Scopes.** Both `applyRbacScope()`s now return nothing (`1 = 0`) when the partner is missing, instead of falling back to the host. This is a second line of defence for callers that don't come through HTTP.
- **Partner delete.**
  - New `Partner::hasBorrowersOrUsers()`. It counts any borrower, and any user other than the partner's own login (that login is deleted together with the partner).
  - `isDeletable()` is false while that returns true, so `check-deletable` reports `is_deletable: false` and `multiple-delete` returns 400 with nothing deleted.
  - `check-deletable` gives the reason `partner.error.has_borrowers_or_users`.
- **Sign-up.** `registerBorrower()` now sets `partner_id` from the signup id's partner.

## Files changed
- `app/Http/Middleware/EnsureUserHasPartner.php` (new)
- `bootstrap/app.php`
- `app/Services/Global/Auth/AuthService.php`
- `app/Services/FCH/Common/Invoice/InvoiceService.php`
- `app/Services/FCH/Common/Payment/PaymentService.php`
- `app/Models/Partner/Partner.php`
- `app/Services/FCH/Partner/PartnerService.php`
- `app/Services/Borrower/Auth/BorrowerAuthService.php`
- `lang/en/auth.php` (`no_partner`), `lang/en/partner.php` (`error.has_borrowers_or_users`)

## Tests
- New tests, each written to fail first and then passing:
  - `Feature/Auth/UserWithoutPartnerTest` (new) — login refused (no partner, deleted partner, borrower); signed-in requests refused; a user with a partner and public routes unaffected.
  - `Integration/Payment/BillingScopeWithoutPartnerTest` (new) — invoice and payment lists empty for a user with no partner, with and without a host on record; the host's view is unchanged.
  - `Feature/Partner/PartnerAdminApiTest` — a partner with borrowers, or with users, cannot be deleted; a partner whose only user is its own login still can.
  - `Integration/Support/BorrowerSelfRegistrationTest` (new) — the sign-up login gets the partner's `partner_id`.
- Fixtures updated to the new rule:
  - `Feature/ActivityLog/ActivityLogVisibilityTest`: its host admins and borrower user had no `partner_id` and relied on the old host fallback. They now belong to the host and the partner.
  - `Feature/Assistant/AssistantAccessTest`: "user with no partner" now expects 403 on the widget config too.
- Suites run: fch-api full suite — 2261 passed, 42 skipped, 0 failed. Real `storage/app` unchanged before and after.

## Open / follow-up
- **Before deploy:** run `SELECT id, email, user_type FROM users WHERE deleted_at IS NULL AND (partner_id IS NULL OR partner_id NOT IN (SELECT id FROM partners WHERE deleted_at IS NULL));`. Every row it returns is locked out after this ships. Give each one the right `partner_id`, or remove it.
- `users.partner_id` is still `nullable()` in the schema. Once the query above returns nothing, a migration can make it NOT NULL so the database enforces the rule as well.
- The admin UI's delete confirmation shows the `reason` from `check-deletable`. Check that the new message reads well there.
