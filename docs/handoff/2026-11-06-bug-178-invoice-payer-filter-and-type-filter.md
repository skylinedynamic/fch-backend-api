# BUG-178: Invoices "Borrower Only" leaves out user payouts, plus a Type filter

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2

## Problem
On host admin > Invoices, **Borrower Only** (`is_mine=false`) also listed Commission Payout invoices paid to host users, for example "QA-TEST Host Staff (user)", $250. The Borrower Only KPI cards (Invoices Sent) included those amounts too.

`FCH\Common\Invoice\InvoiceService` treated Borrower Only as "not a partner" (`entity_type != Partner`). An invoice's payee can also be a **User**: a commission payout to staff gets `entity_type = User` (`CommissionPayout::PAYEE_TYPES`). A user is not a partner, so these invoices landed under Borrower Only. No branch had a fix.

The user also asked for an **invoice Type filter** on the Invoices list.

## Solution
- **Payer filter:** the two copies (list and the unused `getStats`) were replaced by one `applyPayerFilter()`.
  - **Partner Only**: `is_partner_paying = true`, or `entity_type = Partner`, or `type = borrower_fee`. The partner always pays a borrower fee, whichever entity the row was recorded on, and `InvoicePartyResolver` also bills it from the host.
  - **Borrower Only**: `is_partner_paying = false` and `entity_type = Borrower` and the type is not `borrower_fee` (null-safe).
  - A user payout appears only under All Invoice.
  - Second pass, after user review: the first fix left Borrower Only showing borrower fees recorded on the Borrower entity (local E2E data from `E2EUnpaidInvoiceSeeder`). Partner Only also showed only 5 of the 9 borrower fees. Both are fixed by the `borrower_fee` rule above.
- **Type filter:** `Invoice::INVOICE_TYPES` lists the seven types: enrollment, down_payment, installment, platform_fee, borrower_fee, user_seat, commission_payout. `FetchInvoiceRequest` validates `type` against that list, so an unknown type returns 422. `getInvoices()` filters `invoices.type`, and null applies no filter.
- **Type column filter** ("Search by type" in the table header): users type what the table shows ("Borrower Fee"), but the value is stored as `borrower_fee`. The filter now lowercases the value and turns spaces into underscores before the `LIKE`. Before this, it found nothing.
- The KPI cards use the same filtered query, so all of these changes apply to them as well.
- Admin frontend: the Type dropdown is added in `Fch-admin-frontend-react` (see its handoff with the same date).

## Files changed
- `app/Models/Invoice/Invoice.php`: `INVOICE_TYPES`.
- `app/Http/Requests/Api/V1/FCH/Common/Invoice/FetchInvoiceRequest.php`: `type` rule.
- `app/Services/FCH/Common/Invoice/InvoiceService.php`: `applyPayerFilter()` and the type filter.
- `tests/Feature/Invoice/InvoicePayerAndTypeFilterTest.php` (new).
- Admin live E2E: `Fch-admin-frontend-react/e2e/sidebar/invoices/invoices-filters.live.spec.ts` (new).

## Testing
- `InvoicePayerAndTypeFilterTest`, 8 tests. The fixtures follow the real data: a borrower fee as `EnrollmentService` creates it (Partner entity, partner pays), and one recorded on the Borrower entity.
  - The tests cover: Borrower Only without the user payout; Borrower Only KPI ($100); Borrower Only without any borrower fee; Partner Only with every borrower fee, and All with everything; the type filter; the Type column filter matching the shown label; type combined with payer; and an unknown type returning 422.
  - Every new case was red before its fix.
- `tests/Feature/Invoice`, `tests/Feature/Payment` and the two route-access tests: 251 passed.
- Live E2E against the local API and DB (`E2E_BASE_URL=http://localhost:5174 npx playwright test --project=live e2e/sidebar/invoices/`): 7/7 passed. These check that each Type returns only rows of that type, that All Types restores the total, that Borrower Only has no Borrower Fee or Commission Payout, and that Partner Only has every borrower fee. Before the second-pass fix, this spec failed on Borrower Only and Partner Only.
- Live API: host Borrower Only 22 (Enrollment and Installment only) plus Partner Only 22 equals All 44.

## Open items
- The local DB has a legacy row with `type = 'commission payout'` (a space). The type dropdown does not match it. The user confirmed no such rows remain, so it is ignored.
- There is no "User Only" payer option, so user payouts appear only under All Invoice. Not requested.
- The client portal's Invoices page has no Type filter. Not requested.
