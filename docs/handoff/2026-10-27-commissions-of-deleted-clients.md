# Deleting a client removes its unpaid commissions (BUG-107)

Date: 2026-10-27

## Problem
Admin › Commissions › /commissions/21 showed:
- Total Commission $4,000 (10%)
- Requested $0, Funded $0
- every borrower field blank

The row still counted in the Total Commission KPI. Deleting a client (`Borrower::safeDeleteWithRelations`) soft-deleted its funding applications but left its commissions. The commission page then loaded `fundingApplication` without trashed rows and got null. The list already read the trashed application, so the two disagreed, and the totals kept the $4,000.

## Solution
- Deleting a client soft-deletes its commissions that are not paid out (`payment_status != Paid`), the same way a partner deletion removes its commissions. A commission already paid out stays as the record of that payout.
- The commission page loads the funding application and borrower with trashed rows, so a kept commission shows the deal it was paid on.
- A migration soft-deletes the unpaid commissions already left behind: those whose funding application is deleted or missing. This clears commission 21 on staging once migrations run.

## Files changed
- `app/Models/Borrower/Borrower.php`: `commissions()` relation; unpaid commissions deleted with the client.
- `app/Services/FCH/Common/Commission/CommissionService.php`: `getCommissionById()` loads trashed funding application and borrower.
- `database/migrations/2026_10_27_120000_remove_unpaid_commissions_of_deleted_funding_applications.php`: new. One-off clean-up; `down()` does nothing.
- `tests/Feature/Commission/CommissionOfDeletedClientTest.php`: new. Unpaid and pending commissions go with the client; a paid one stays and shows its funded amount; the migration clears only unpaid orphans.

## Testing
- The new tests failed first, then passed. `tests/Feature/Commission`, `CommissionPayout` and `Borrower`: 250/250, plus the migration test.

## Open items
- Run `php artisan migrate` on staging to clear the existing row.
- Commissions are still computed once, when an application is Closed Completed. A later change to the funded amount does not recalculate them (not part of this bug).
