# BUG-195: Payments list shows the payer's company

**Date:** 2026-11-06

## Problem
On the Payments list, in the host and partner portals, the COMPANY column showed "-" on every row, even for payers with a company. Decision D8: the column shows the company name. The Invoices list already showed it for the same payers.

`PaymentResource::getCompanyName()` reads the borrower's `business_name` or the partner's `company_name`. `PaymentService::list()` eager-loaded `partner:id,full_name` and `borrower:id,first_name,last_name`, so those two columns were never loaded and the value was always null.

## Solution
`PaymentService::list()` now also loads `partner.company_name` and `borrower.business_name`. Who sees which rows (RBAC) and how the company is picked are unchanged.

## Files changed
- `app/Services/FCH/Common/Payment/PaymentService.php`: the two extra columns in the list's eager load.
- `tests/Feature/Payment/PaymentListCompanyTest.php` (new, 3 tests):
  - the host sees a partner's and its own client's company
  - a partner sees its own company and its client's
  - a payer without a company gets null
- Admin panel: `Fch-admin-frontend-react/e2e/sidebar/payments/payments-company.live.spec.ts` (new).

## Testing
- **New test:** failed first (company null on every row), then passed.
- **Suites:** `tests/Feature/Payment`, `tests/Feature/CommissionPayout` and `UserWithoutPartnerTest` (227 tests) passed.
- **Local MySQL through the real endpoint:**
  - host: all 8 rows now carry a company ("1 dollar trial", "Alpha Tech Solutions", "Exodiass", …). Before the fix all 8 were null.
  - partners 42 and 38: each sees its own company.
- **Admin live spec:** failed on the old code (no row with a company), then passed. `e2e/sidebar/payments/` live: 8/8.

## Open items
- For a commission paid to a host user (`entity_type` User), the company is the payment partner's, as before. Not changed.
- Not committed.
