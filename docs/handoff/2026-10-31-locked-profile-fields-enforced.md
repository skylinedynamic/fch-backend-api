# Profile: locked fields are enforced by the API; the EIN is optional (BUG-054, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-054** (R2-CP-PROF-01, High/P1): on client 144 › Profile › Business Details, Business Address is locked (read-only, with a Change Request button). With the read-only attribute removed in the browser and a new address typed, Save Changes returned `200 "Business information updated successfully"` and the address changed at once — no Change Request, no host review. The defect log showed it as Fixed; nothing was in place.

The lock lived only in the borrower portal (`src/config/profileLockFields.ts`: a listed field is locked once it holds a value). `BusinessInfoService::updateBusinessInfo()` and `FinancialInfoService::updateFinancialInfo()` wrote every field they were sent.

Separately (requested with this fix): the business EIN is no longer required. Once saved it is locked like the other business fields.

## Solution
- `Borrower::LOCKED_BUSINESS_FIELDS` (EIN, address, phone, website, email, D-U-N-S, start date), `LOCKED_FINANCIAL_FIELDS` (monthly and annual revenue, annual personal income) and `LOCKED_BANK_ACCOUNT_FIELDS` (bank name, account open date) — the same fields the portal locks.
- `App\Support\Profile\LockedFields::changed()` — the locked fields whose sent value differs from a **saved** one. An empty field may be filled in; a value sent back unchanged is fine (the form sends every field). Comparison ignores how a value is written: EIN/phone/D-U-N-S by digits, dates as dates, amounts to the cent, the rest trimmed and case-insensitive.
- `UpdateBusinessInfoRequest` and `UpdateFinancialInfoRequest` add a 422 per changed field — `borrower_validation.locked_field_change_request`: "This field is locked once saved. Submit a change request to update it." Bank accounts are checked per saved row (`bank_accounts.{i}.bank_name`). The borrower is read fresh for the check.
- The services keep a saved, locked field's stored value whatever was sent, so a value that only differs in writing (e.g. email case) is not rewritten.
- EIN: `nullable` in `UpdateBusinessInfoRequest`; `ProfileCompletionService` no longer needs it for Business Details to be complete. The request now reduces the EIN with `BusinessEin::canonical()` instead of `normalize()`, so letters (`AB-CDEFGHI`) still fail the rule instead of being stripped to "no EIN".
- Change Requests still require a value for the EIN (`StoreChangeRequest`) — a request has to say what to change it to.

## Files changed
- `app/Models/Borrower/Borrower.php` — the three lock lists.
- `app/Support/Profile/LockedFields.php` — new.
- `app/Http/Requests/Api/V1/Borrower/Profile/UpdateBusinessInfoRequest.php` — EIN optional, `canonical()`, lock check.
- `app/Http/Requests/Api/V1/Borrower/Profile/UpdateFinancialInfoRequest.php` — lock check incl. saved bank accounts.
- `app/Services/Borrower/Profile/BusinessInfoService.php`, `FinancialInfoService.php` — saved locked values are kept.
- `app/Services/Borrower/Profile/ProfileCompletionService.php` — EIN not required for completeness.
- `lang/en/borrower_validation.php` — `locked_field_change_request`.
- `tests/Feature/Borrower/LockedProfileFieldsTest.php` — new (14 tests).
- `tests/Feature/Borrower/BorrowerProfileApiTest.php` — the bank sync test no longer renames a saved account (now refused; covered by the new test); its other checks are unchanged.

## Testing
- `LockedProfileFieldsTest` failed first (12 of 14: every locked change returned 200, the EIN was required and counted for completeness), then passed.
- `tests/Feature/Borrower`, `tests/Feature/Profile`, `tests/Integration/Profile`, `tests/Unit` — OK, 1554 tests.
- Full PHPUnit suite — see the session report for the count.
- Borrower portal E2E `business-details-locked-bypass.spec.ts` against this API — passed (see the client-fch handoff).

## Open items
- A saved bank account can still be **removed** (the portal has a delete button on each row, and the sync deletes rows not sent back). Whether removing a locked account should also need a Change Request is a product decision.
- Admin/host edits to a client (`UpdateBorrowerRequest`) are not locked — the host is who approves changes.
