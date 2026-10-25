# Plan & Fee — each refused value comes back with a message for its field (BUG-096)

**Date:** 2026-10-25
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-096** (OP-PF-04): Plan & Fee › Partner Enrollment › Certified Partner One-Time, Total Amount = 0 → Save → Confirm & Save **saved the plan at $0.00**; -5 failed only with a generic "Failed to update plan"; empty was also accepted. The sheet had it as Fixed, but no fix was on the branch.

`PlanFeeController::update()` validated inline with `total_amount => nullable|numeric|min:0` — `min:0` lets 0 through and `nullable` lets nothing through — and Laravel's default 422 was not read by the screen. An amount over the `decimal(10,2)` column would have failed in the database (500).

## Solution
- New `UpdateSubscriptionPlanRequest` (extends `BaseApiRequest` — one message per field):
  - `total_amount`: **required, numeric, greater than 0**, at most 99,999,999.99 (the column);
  - `platform_fee`: only Partner Enrollment plans send one; when sent it is **required and greater than 0** (as decided), same maximum;
  - `total_installment_count_upto`: optional, whole number, at least 1;
  - `total_seat_count`: optional, whole number, 0 or more;
  - `include_existing_subscription`: optional boolean.
- Messages in the new `lang/en/plan_fee.php`, e.g. "Enter the total amount.", "The total amount must be greater than 0.", "Enter the platform fee.", "The platform fee must be greater than 0."
- `authorize()`: host team only — someone else gets 403 before any value is checked.
- The controller uses `$request->validated()`; the service is unchanged.

All 12 plans locally are above 0 (lowest $49.00), and every Partner Enrollment plan has a fee above 0 ($299 / $399; the other groups have none and send none), so no existing price is affected.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Admin/PlanFee/UpdateSubscriptionPlanRequest.php` — new.
- `app/Http/Controllers/Api/V1/FCH/Admin/PlanFee/PlanFeeController.php` — uses it.
- `lang/en/plan_fee.php` — new.
- `tests/Feature/Billing/PlanFeeValidationTest.php` — new, 17 tests.

## Tests
- New: 0, negative, empty, missing, non-numeric and too-large amounts, a 0 / negative / empty / non-numeric platform fee, 0 / fractional installments and negative seats → 422 with that field's message and the price unchanged (12 failed first); a positive amount and fee save; a plan sending no fee keeps its own; outsiders get 403.
- `tests/Feature/Billing`: 51 passed. Full suite: see the session report.
- Real local API from the admin screen: 0 → "The total amount must be greater than 0.", empty → "Enter the total amount."; platform fee 0 → "The platform fee must be greater than 0.", empty → "Enter the platform fee."; plan 1 still $4,997.00 / $299.00.

## Open items
- Mark BUG-096 in the QA defect log In Progress (it was marked Fixed), then Fixed after the stage retest.
