# Credit Accounts — bulk delete of unapplied cards, inactive cards left out of plays (BUG-042)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-042** (OP-CACC-10, Medium/P2): in Admin › Credit Builder › Credit Accounts there was no way to delete a card or to set it Active/Inactive, so QA could not remove its test card (#87).

On the API side delete (soft) and status already existed, but:
- delete removed any card, including ones borrowers had applied for — their application history would point at a deleted card;
- an **Inactive card was still shown in Capital Access plays** (only the Credit Accounts list hid it), and a deleted card left a **blank step** in its plays;
- the update endpoint accepted any `status` string.

## Solution
- **Delete only cards no borrower has applied for** — from Credit Accounts (`credit_account_applications`) or through a play step (`capital_access_applications` on a sequence using the card). New `POST /admin/credit-builder/credit-accounts/multiple-delete` `{ids}`; the single `DELETE` keeps the same rule. A selection with an applied card is refused as a whole (422, `errors.applied: [{id, name}]`), nothing deleted — same pattern as Leads' multiple delete.
- **`can_delete`** on each card in the admin list (and the all-active list), loaded with `withExists` (no N+1), so the admin panel disables the checkbox.
- **Status** on create/update is `Active` or `Inactive` only.
- **Inactive or deleted cards are not offered in plays.** One rule, `CapitalAccessSequence::offered()` (its card is `available()` and not deleted), used everywhere the borrower's plays read their steps: the play list, play detail, roadmap, step detail, step submit, the step-order check and the completed-steps count — so a play still completes without the hidden step and the next step opens after it. Reactivating the card brings the step back.
- `CreditAccount::available()` is the one "switched on" rule; `offeredTo()` now uses it.

No permission checks were added server-side (the admin panel checks `delete credit accounts`), matching how these routes work today.

## Files changed
- `app/Models/CreditBuilder/CreditAccount.php` — `available()`, `playApplications()`, `hasBorrowerApplications()`, `withApplicationFlags()`.
- `app/Models/CreditBuilder/CapitalAccessSequence.php` — `offered()` scope.
- `app/Services/Borrower/CapitalAccess/CapitalAccessStrategyService.php` — every borrower-side step query uses `offered()`.
- `app/Services/FCH/Admin/CreditBuilder/CreditAccountService.php` — `deleteMultiple()`, `delete()` through it, flags on the lists.
- `app/Http/Controllers/Api/V1/FCH/Admin/CreditBuilder/CreditAccountController.php` — `multipleDelete()`, refused deletes answer 422.
- `app/Http/Requests/Api/V1/FCH/Admin/CreditBuilder/CreditAccount/DeleteCreditAccountsRequest.php` — new.
- `app/Http/Requests/.../StoreCreditAccountRequest.php`, `UpdateCreditAccountRequest.php` — `status` in Active/Inactive.
- `app/Http/Resources/FCH/CreditBuilder/CreditAccountResource.php` — `can_delete`.
- `app/Exceptions/CreditAccountAppliedException.php` — new.
- `routes/admin.php` — the multiple-delete route.
- `lang/en/credit_account.php` — three messages.
- `tests/Feature/CreditBuilder/AdminCreditAccountDeleteAndStatusTest.php` — new, 7 tests.
- `tests/Feature/CreditBuilder/CapitalAccessUnavailableCardTest.php` — new, 5 tests.

## Tests
- New 12 tests; all failed first (405 no route, applied cards deleted, no `can_delete`, any status accepted, inactive/deleted cards listed, the hidden step blocking the next one and completion), pass after.
- `tests/Feature/CreditBuilder`, `tests/Integration/CreditBuilder`, `tests/Feature/CapitalAccess` — 155 passed.
- Full suite: 3,442 passed, 2 skipped (MySQL-only column length; POSIX key permissions).

## Open items
- Server-side permission checks on the credit-account routes (`update/delete credit accounts`) — today any host-team user who reaches them can change or delete a card.
- A step hidden from a play keeps its earlier application rows; the roadmap no longer lists it.
- Mark BUG-042 in the QA defect log back to In Progress, then Fixed after the stage retest.
