# Plan & Fee amounts are kept to the cent (BUG-076)

**Date:** 2026-11-04
**Branch:** bugfix/round-2

## Problem
In Host admin › Plan & Fee › Borrower Enrollment, a One Time Payment Total Amount of `10.555` passed the check.
- The "Confirm Rate Changes" dialog showed `$2497.00 -> $10.55`, with no field error.
- `UpdateSubscriptionPlanRequest` had no rule on decimal places, so `validate-subscription-plan` and `update-subscription-plan` both accepted 3 decimals.
- The columns are `decimal(10, 2)`, so MySQL would have saved `10.56`.
- The dialog formats with JavaScript `toFixed(2)`, and `10.555` is held as `10.5549…`, so it showed `10.55`. The admin was asked to confirm a price that differed from the one that would be saved.

## Solution
- **Rule:** `total_amount` and `platform_fee` get `decimal:0,2` (`UpdateSubscriptionPlanRequest::AMOUNT_DECIMALS`). It applies to both the check and the save, since they share the request. `10`, `10.5`, `10.56` and `10.50` are accepted; `10.555` is refused.
- **Messages:** `plan_fee.total_amount_decimals` and `plan_fee.platform_fee_decimals` are keyed to their fields. The portal already shows a 422 refusal under its field (BUG-096), so the dialog never opens.
- **Where the check lives:** the format check is on the server, following the project rule; the portal does not round or check the value itself.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Admin/PlanFee/UpdateSubscriptionPlanRequest.php`: the `decimal:0,2` rule and its messages.
- `lang/en/plan_fee.php`: `total_amount_decimals` and `platform_fee_decimals`.
- `tests/Feature/Billing/PlanFeeValidationTest.php`: the new cases.

## Testing
- **`PlanFeeValidationTest`:**
  - Refused, by both save and check: `10.555` as a number, `'10.555'` as typed, and a platform fee of `'1.005'`.
  - Saved as given: `10`, `'10.5'`, `'10.56'` and `'10.50'`.
  - The two new messages exist.
  - The 3-decimal cases failed first: 7 failures before the fix.
- **`tests/Feature/Billing`:** 99 passed.
- **react-fch:** see its handoff with the same name.

## Open items
- None for this bug.
- Local data: the live Plan & Fee spec cannot find the Borrower Fee tab, because `SubscriptionPlan::active()` does not return the local `borrower_fee` plan. This predates the change and is not related to it.
