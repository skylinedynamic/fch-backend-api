# Credit account status: any status from any other is by design (BUG-028)

**Date:** 2026-11-01
**Branch:** bugfix/round-2
**Author:** awlad

## Problem
QA defect **BUG-028** (R2-CP-CBA-01). `POST /api/v1/borrower/credit-builder/credit-accounts/{id}/apply` with `status=Approved` on an account the borrower had never applied to (N/A) answered 200 "Application marked as approved." QA expected Requested → Processing → Approved to be enforced.

**Decision (product owner): by design, not a defect.** The vendor approves the account outside the portal. The status is only the borrower's own note of where their application stands, so any status may follow any other.

## Solution
No behaviour change. The decision is written down so it is not "fixed" later:
- `CreditAccountService::apply()` has a BY DESIGN note naming BUG-028 and the test that pins it.
- Its allowed statuses use `CreditAccountApplication::STATUS_*` instead of string literals, and the model is imported instead of being fully qualified inline.

The client portal's Credit Accounts page was redesigned the same day, with matching notes and tests. See client-fch `docs/handoff/2026-11-01-credit-accounts-redesign-and-bug-028-by-design.md`.

## Files changed
- `app/Services/Borrower/CreditBuilder/CreditAccountService.php`: the BY DESIGN docblock, the status constants and the import.
- `tests/Feature/CreditBuilder/BorrowerCreditBuilderApiTest.php`: new test `a_borrower_may_mark_any_status_from_any_other_by_design`.

## Tests
- **Added:** `a_borrower_may_mark_any_status_from_any_other_by_design`.
  - N/A → Approved is 200 with `data.status` Approved.
  - Approved → Requested is 200 with `data.status` Requested.
- **Run:** `BorrowerCreditBuilderApiTest`, 28 passed (PHP 8.3 with pdo_sqlite).

## Open / follow-up
- QA sheet: mark BUG-028 as *Not a bug / By design*.
