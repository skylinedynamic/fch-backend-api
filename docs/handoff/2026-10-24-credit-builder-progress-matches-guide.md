# Credit Builder — Overview progress matches the Step-By-Step Guide (BUG-024)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-024** (CP-CBO-02, Medium/P2): the borrower portal's Credit Builder › Overview and › Step-By-Step Guide gave different progress for the same client (e.g. Overview "Tier 4 100%", Guide "77% · 20/26 tasks"; after one verified task the Overview read "Tasks completed: 0/7").

Causes in `OverviewService::myProgress()` (`GET /borrower/credit-builder/my-progress`):
- `current_tier_completed_tasks` was never returned, so `MyProgressResource`'s `current_tier_complete_task` was always **0**.
- The overall total was every tier's own tasks **plus** migrated tasks, while the Guide shows a tier's migrated tasks **instead of** its own — totals differed (12 vs 16 on stage).
- The current tier counted only its own tasks: a tier made only of migrated tasks had 0 tasks and read **100% · 0/0**.
- Completed counted every `verified` request row, not each task's latest status as the Guide shows.

Also found: the Guide read a migrated task's status with `->latestBorrowerTierTask?->where('migrated_task_id', …)->first()` — on a model that starts a new, unscoped, unordered query, returning the **oldest** request for that migrated task, so a re-submitted-and-rejected task still showed "verified".

Checked on the local `fch-migration` database (read-only, rolled back): all 11 borrowers with progress showed a mismatch before; all 11 match after.

## Solution
New `App\Services\Borrower\CreditBuilder\BorrowerTaskBoard`: the tasks a borrower is shown per tier (migrated tasks replace the tier's own) with each task's **latest** request. Both pages now use it:
- `OverviewService::myProgress()` — overall and current-tier counts, percentage and estimated days come from the board; `current_tier_completed_tasks` is returned. A tier with no tasks at all still counts as 100%.
- `CreditBuilderStepGuideService::getTierWiseTaskList()` — a migrated task's status comes from the board (latest request).

No change to the API's response shape; only the values are now correct. The borrower portal's Overview shows the overall % (see client-fch handoff of the same date).

## Files changed
- `app/Services/Borrower/CreditBuilder/BorrowerTaskBoard.php` — new: tasks shown per tier with their latest request.
- `app/Services/Borrower/CreditBuilder/OverviewService.php` — `myProgress()` counts from the board, returns `current_tier_completed_tasks`; unused imports removed.
- `app/Services/Borrower/CreditBuilder/CreditBuilderStepGuideService.php` — migrated task status from the board.
- `tests/Feature/CreditBuilder/CreditBuilderProgressConsistencyTest.php` — new, 6 tests.

## Tests
- New `CreditBuilderProgressConsistencyTest` (6): current tier's completed count; another tier's task does not count; migrated-only tier reports its progress (not 100% · 0/0); Overview and Guide agree overall and on the current tier; a task counts by its latest status; the Guide shows a migrated task's latest status. All failed before the fix (0 completed, 6 vs 4 total, Guide showing the oldest status), pass after.
- `tests/Feature/CreditBuilder` — 76 passed.
- Full suite: see the session report.

## Open items
- The Guide 500s (`CreditBuilderVendorResource` on null) if a migrated task's `TierTask` has no vendor. Real migrated tasks always have one (`migrated_tasks.vendor_id` is required), so it is not hit today; worth a null-safe resource.
- The Guide still eager-loads `task.latestBorrowerTierTask` for migrated tasks, now unused — harmless, can be dropped.
- Mark BUG-024 in the QA defect log back to In Progress, then Fixed after the stage retest.
