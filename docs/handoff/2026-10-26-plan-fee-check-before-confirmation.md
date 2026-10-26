# Plan & Fee — the values are checked before the confirmation dialog (BUG-096 retest)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-096**, retest on 26 Oct: Fail. Total Amount 0, -5 or empty → Save opened "Confirm Rate Changes" ($4997.00 → $0.00) before anything was checked. QA cancelled, so the API's validation (2026-10-25: `UpdateSubscriptionPlanRequest`) was never reached and no message appeared. A bad value read as accepted.

## Solution
**New check-only route:** `PUT /api/v1/admin/plan-fee/validate-subscription-plan/{id}` → `PlanFeeController::validatePlan()`.
- It uses `UpdateSubscriptionPlanRequest`: the same rules, messages and host-only access as saving.
- Valid values answer 200 "The values are valid." (`plan_fee.values_valid`) and **nothing is saved**. Bad values answer 422 with the field's message.
- The admin screen calls it on Save, before the dialog. Saving still validates too.

## Files changed
- `routes/admin.php`: the route.
- `app/Http/Controllers/Api/V1/FCH/Admin/PlanFee/PlanFeeController.php`: `validatePlan()`.
- `lang/en/plan_fee.php`: `values_valid`.
- `tests/Feature/Billing/PlanFeeValidationTest.php`: +15 tests.

## Tests
- **New:**
  - the check refuses every value saving refuses (the same 13-case data set, the same messages);
  - good values pass and the plan is unchanged;
  - outsiders get 403.
- All 15 failed first. `tests/Feature/Billing`: 70 passed.

## Open items
- The admin screen change is in the admin handoff of the same date.
- QA sheet: BUG-096 → Fixed after the stage retest (admin `bugfix/all-bugfix` must be on stage).
