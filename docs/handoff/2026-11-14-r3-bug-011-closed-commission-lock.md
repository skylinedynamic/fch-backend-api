# R3-BUG-011: a closed application's funded amount and commission are locked in the API

Date: 2026-11-14

## Problem

"Locked after close" lived in the portal only (FCF-47 D04). Through the API:

- **A. Re-closing with another amount.** QA posted `POST common/request-application/funding-application/status-update` again for app 106, already Closed (Completed) at $15,000, with `funded_amount: 30000`.
  - The answer was 200, and the funded amount became $30,000.
  - The commission was recomputed ($3,000 success fee) and set back to **Processing / Unpaid**.
  - This also happened after a real payout: commission 33, Completed/Paid, went back to Processing/Unpaid.
- **B. Saving a commission past Draft.** QA posted `POST admin/funding-application/save-commission` for app 88, whose commission was Processing.
  - The answer was 200, and the success fee became 50%.
  - The commission was set back to **Draft / Pending**. A paid-out commission was reset the same way.

Causes:
- `applyFundingApplicationStatus()` treated a re-close as a repeat only when the amount was identical. Otherwise it wrote the new amount, then recomputed the commission and forced Processing/Unpaid.
- `CommissionService::saveCommission()` always wrote Draft/Pending through `updateOrCreate`.

## Solution

- **The funded amount is fixed once closed.** If the application is already Closed (Completed) and the request closes it again with a different `funded_amount`, it is refused with 422 `application.funded_amount_locked`. Nothing changes.
  - The same amount sent twice (a double-click) is still a quiet repeat.
- **The commission is fixed past Draft.** `save-commission` refuses a commission that is not Draft (Processing, Completed, Declined) with 422 `application.commission_locked`.
  - A Draft commission is still set and reset, before or after the close. This is the portal's Commission Details card and its close flow.
- **A paid-out commission is never overwritten.** The close-time recompute skips a commission that is Completed or Paid, however the application comes to be closed again.
- **New model helpers:** `Commission::isPastDraft()` and `Commission::isPaidOut()`.

## Files changed

- `app/Models/Commission/Commission.php`: `isPastDraft()`, `isPaidOut()`.
- `app/Services/FCH/Common/Application/RequestApplicationService.php`: refuses a re-close with another amount, and the recompute skips a paid-out commission.
- `app/Services/FCH/Admin/Commission/CommissionService.php`: `saveCommission()` refuses a commission past Draft.
- `lang/en/application.php`: `funded_amount_locked`, `commission_locked`.
- `tests/Feature/Commission/ClosedApplicationCommissionLockTest.php` (new, 8). It covers:
  - scenario A, for both a Processing/Unpaid and a paid-out commission
  - the same close repeated changes nothing
  - scenario B, for both
  - a Draft commission is still saved
  - the first close still starts the commission (Processing/Unpaid on the funded amount)
  - closing never overwrites a paid commission

## Testing

- **Written first:** 5 of the 8 failed before the change. The other 3 (repeat, Draft save, first close) pin behaviour that stays.
- **`tests/Feature/Commission`, `CommissionPayout`, `Application`:** 231 passed.
- **Full suite (after R3-BUG-011 to 014):** 5,562 tests, all passed (2 skipped).

## Open items

- **Staging data (by hand):** QA restored the amounts, but the statuses stay as reset.
  - App 106's commission 33 was paid out, so it should be Completed/Paid. It is now Processing/Unpaid or Draft/Pending.
  - App 88's commission should be Processing/Unpaid. It is now Draft/Pending.
  - Fix both rows on the server. The code change does not restore them.
- **Admin message:** the portal shows its generic "Failed to save commission" toast if a locked save is ever attempted. The portal normally doesn't offer it, because the lock is in the UI too.
- **Assignment:** the sheet assigns this to Arafat; it was done here on request.
