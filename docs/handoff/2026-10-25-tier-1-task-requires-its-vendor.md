# Manage Tier — a Tier 1 task cannot be saved without its vendor (BUG-095)

**Date:** 2026-10-25
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-095** (OP-TIER-09): on Tier 1 › Add Task the Related Vendor list was empty, and a Tier 1 task (verified through its vendor) could not be completed. Reproduced locally the other way round: opening Add Task before the task list's own tier request answered, the dialog treated Tier 1 as any other tier and **saved the task with no vendor** (`vendor_id = null`). The API accepted it: `vendor_id` was optional for every tier. (Admin panel side: see its handoff of the same date.)

## Solution
`Concerns\VendorTierTaskRules`, used by `StoreTierTaskRequest` and `UpdateTierTaskRequest`: a task on a vendor tier (`tiers.has_vendor`, Tier 1) that is not manually verified must name a vendor — 422 on `vendor_id`, "Vendor is required". Judged on the task as it will be saved (what the request sends, else what the edited task has), so an edit that leaves the vendor alone is fine and one that removes it is refused. Other tiers and manually verified tasks are unchanged.

Locally Tier 1 has no vendor-less automated tasks, so no existing task is caught by the rule.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Admin/CreditBuilder/TierTask/Concerns/VendorTierTaskRules.php` — new.
- `app/Http/Requests/Api/V1/FCH/Admin/CreditBuilder/TierTask/StoreTierTaskRequest.php`, `UpdateTierTaskRequest.php` — use it.
- `lang/en/tier_task.php` — `validation_vendor_required`.
- `tests/Feature/CreditBuilder/VendorTierTaskNeedsVendorTest.php` — new, 6 tests.

## Tests
- New: Tier 1 without a vendor → 422 (create) and an edit removing it → 422 — both failed first (201 / 200), pass after; with a vendor, manual verification, another tier, an edit leaving the vendor → saved.
- `tests/Feature/CreditBuilder`: 94 passed. Full suite: see the session report.

## Open items
- Mark BUG-095 in the QA defect log In Progress, then Fixed after the stage retest. "No Add Task on Tier 4" is by design (Tier 1 only).
