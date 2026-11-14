# BUG-179: Payments list NAME column sorts by payer name

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2

## Problem
Host admin > Payments: clicking the **NAME** header sorted the rows by date, oldest first. A second click went back to the default order (newest first). `aria-sort` stayed `none`.

There were two causes:
- **Admin:** the Name column (`PaymentTable.jsx`) was `sortable` but had no `field` or `sortField`, because the name is rendered by `nameBodyTemplate`. A click sent `sort_field: null`.
- **API:** `FCH\Common\Payment\PaymentService::list()` falls back to `payments.transaction_date` for an unknown sort field. It had no sort for the combined payer name the column shows. There are separate `partner_name` and `borrower_name` sorts, but neither covers all rows.

## Solution
- **API:** a new `sort_field = name` orders by `COALESCE(partner full_name, borrower first + ' ' + last, user name)`, joined the same way `PaymentResource` names the payer:
  - a Partner payment uses `payments.partner_id`;
  - a Borrower payment uses `payments.borrower_id`;
  - a User payment (commission payout to staff) uses `payments.entity_id`.
  - Each join is limited to its `entity_type`, and `payments.id` breaks ties. The joins are aliased (`name_partner`, `name_borrower`, `name_user`), so they do not clash with the other sort or filter joins.
- **Admin:** the Name column has `sortField="name"`.

## Files changed
- fch-api `app/Services/FCH/Common/Payment/PaymentService.php`: the `name` sort.
- fch-api `tests/Feature/Payment/PaymentListNameSortTest.php` (new).
- Fch-admin-frontend-react `src/pages/payments/components/PaymentTable.jsx`: `sortField="name"`.
- Fch-admin-frontend-react `tests/pages/payments/components/PaymentTable.test.tsx`: a NAME sort test.

## Testing
- `PaymentListNameSortTest`: a partner, two borrowers and a host user, with dates running opposite to the names. It checks asc and desc. Red first (date order came back), then green.
- `PaymentTable.test.tsx`: "asks to sort by the payer name". Red first (`sortField: null`), then green. `tests/pages/payments` passes 18/18. `npm run typecheck` is clean.
- `tests/Feature/Payment` and `tests/Feature/CommissionPayout`: 216 passed.
- Live API (local DB, host): `sort_field=name` returns 48 rows correctly ordered asc and desc. The KPI totals are unchanged.

## Open items
- The **Type** column (`transaction_type`) is also `sortable`, but the API has no sort for it, so it also sorts by date. QA did not report it. Two ways to fix it: add a Debit/Credit sort, or remove `sortable`. The user has not decided yet.
