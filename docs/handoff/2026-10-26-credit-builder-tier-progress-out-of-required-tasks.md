# Credit Builder — the current tier counts out of the tasks it needs, and the admin sees the same progress (BUG-024)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-024** (CP-CBO-02). Retest on 25 Oct: **Partially Fixed**. The Overview and the Step-By-Step Guide now agree on the overall progress (Tier 1, 50%), but the Overview said "Tasks completed in this tier: **0/8**" while the Guide said Tier 1 **0/1**.

**How tiers work** (`TaskVerificationService::tierUpdate`, confirmed with the product owner):
- Tier 0's tasks must **all** be done.
- From Tier 1 on, a tier needs only `task_to_complete` of its tasks (1). Doing one opens the next tier and the task moves into it (a migrated task).
- Tier 4 (the last) is complete when its task is done.

**What was wrong:**
- `OverviewService::myProgress()` divided the current tier by **every** task it lists.
- `TierService::getBorrowerProgress()` (the admin/partner view of a client) still used the old count: it ignored `task_to_complete` and migrated tasks, and counted every verified request, including a resubmitted task twice.
- The estimated completion added up the days of every open task: 240 days for Tier 1's eight 30-day tasks, when one task (30 days) finishes it.
- **Local data:** clients #123 and #41 (Tier 1) read Overview 0/8, Guide 0/1, admin 0/8. Client #1 (Tier 4, three migrated tasks, two verified) read Overview 2/3, Guide 2/1, admin 2/2.

## Solution
- **New `BorrowerTaskBoard::tierProgress(Tier)`**, the current tier's figures from the tasks the Guide lists:
  - **required:** `task_to_complete`, capped at the tasks listed; every task when it is not set;
  - **completed:** verified tasks, capped at what is required, so more than needed reads 1/1, not 2/1;
  - **remaining** and **percentage:** out of the required tasks;
  - **estimated days:** the **longest** task still open (as decided), 0 once nothing is left.
- **`OverviewService::progressFor(Borrower)`** builds the whole progress. `myProgress()` calls it for the signed-in borrower, and `TierService::getBorrowerProgress()` now calls the same method, so admin, the Overview and the Guide always agree.
- The overall figures are unchanged: every task the Guide lists, by its latest status.
- **Result on local data:** #123 and #41 read 0/1 everywhere; #1 reads 1/1, 100%, "All tiers complete".

## Files changed
- `app/Services/Borrower/CreditBuilder/BorrowerTaskBoard.php`: `tierProgress()`.
- `app/Services/Borrower/CreditBuilder/OverviewService.php`: `progressFor()`; `myProgress()` delegates to it.
- `app/Services/FCH/Admin/CreditBuilder/TierService.php`: `getBorrowerProgress()` delegates to `OverviewService::progressFor()`. The old inline count is removed.
- `tests/Feature/CreditBuilder/CreditBuilderProgressConsistencyTest.php`: +5 tests.

## Tests
- **New:**
  - a tier needing 1 of 2 reads 0/1;
  - a last tier with 3 migrated tasks, 2 verified and 1 needed reads 1/1, 100%, 0 days;
  - Tier 0 still needs every task;
  - the estimate is the longest open task (10, not 5 + 10; 45, not 30 + 45);
  - `POST /api/v1/admin/credit-builder/tiers/borrowers/progress` returns the client's figures.
- Four of the five failed first; "Tier 0 still needs every task" passes either way.
- The 6 existing consistency tests (tiers without `task_to_complete`) still pass. `tests/Feature/CreditBuilder`: 100 passed.
- **Full suite:** 3,760 tests, 1 failure, 2 skipped. The failure is not from this change: `NavigationMapTest` › `capital_access` (client-fch moved Capital Access to `/credit-card-stacking`).


## QA retest 26 Oct (stage, before this change was deployed) — and what changed
QA reported 2 of 4 points still wrong:
1. **Tier 4 with nothing listed yet:** Overview "0/0", Guide "0/1".
2. **The Guide's total was "7 of 16" while its rows add up to 12** (Tier 0 7/8, Tiers 1–4 0/1 each). Admin showed "Overall Program 131%, 21 of 21".

Point 2's admin part was the old `getBorrowerProgress()` count, already replaced above. Stage did not have it yet. The rest came from two rules, one for the rows and one for the total:
- `tierProgress()`: a tier with nothing listed yet still needs its `task_to_complete` (0/1, as in the Guide). A tier with no requirement and nothing listed needs nothing.
- `BorrowerTaskBoard::count()` (the overall figure) is now the **sum of every tier's `tierProgress()`**, needed and completed. The total is what the Guide's rows add up to, and never over 100%. QA's client would read 7 of 12 (58%) on the Overview, the Guide and admin.

**Tests:** +3 in `CreditBuilderProgressConsistencyTest`:
- a tier with no tasks yet reads 0/1;
- the total is the sum of what each tier needs (2/4, where counting every listed task gives 2/5);
- the total never passes 100% (3 + 1 + 1 with extra tasks done), and admin agrees.

All three failed first. `tests/Feature/CreditBuilder`: 103 passed. Full suite: 3,763 tests, the same single unrelated `NavigationMapTest` failure.

**Local data:** 54 clients checked. For every one, the Guide's rows add up to the API's total and admin's percentage matches; none is over 100%. #1: 12/12, 100%. #41 and #123: 8/12 (67%), Tier 1 0/1.

## Open items
- The client portal's Guide cap ("2/1" → "1/1") and the Overview redesign are in client-fch `docs/handoff/2026-10-26-credit-builder-overview-redesign-and-tier-counts.md`.
- QA sheet: BUG-024 → Fixed after the stage retest.
