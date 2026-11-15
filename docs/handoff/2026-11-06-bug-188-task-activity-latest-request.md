# BUG-188: Task activity lists each task once, as its latest request stands

**Date:** 2026-11-06

## Problem
Client → Credit Builder → Overview → Task activity kept old rejected requests under **Needs attention** (with "Fix in the guide") after the task had been resubmitted and verified.

QA saw All 13 = Needs attention 3 + Verified 10, while the guide said 10 of 12 tasks were verified.

Each submission creates a new `borrower_task_verification_requests` row (`CreditBuilderStepGuideService`, `create()`), and the old rows are kept. `OverviewService::taskOverview()` returned **every** row, and `TaskOverviewResource` split them by status. As a result:
- A fixed task appeared both as rejected and as verified.
- A task verified several times was counted several times.

On local borrower 1, 32 rows (25 verified, 7 rejected) cover 16 tasks.

## Solution
`OverviewService::taskOverview()` takes only each task's **latest** request (`MAX(id)` per `task_id` for the borrower). The latest request is where the task stands now. Older requests stay in the database as history. The response shape is unchanged, so no frontend change was needed.

## Files changed
- `app/Services/Borrower/CreditBuilder/OverviewService.php`
- `tests/Feature/CreditBuilder/TaskOverviewLatestRequestTest.php` (new)

## Testing
- `TaskOverviewLatestRequestTest` failed first (Grainger listed twice; Phone Listing's old verification listed as verified), then passed. It covers:
  - rejected then verified
  - two old rejections then verified
  - verified three times
  - verified then rejected (needs attention)
  - rejected then in review
- `tests/Feature/CreditBuilder`: 127 passed.
- Local MySQL, borrower 1: 14 verified + 2 rejected, i.e. each of 16 tasks once. Before, it was 25 + 7 rows.
- Client E2E (client-fch `e2e/sidebar/credit-builder/overview/overview.spec.ts`, new Task activity test): passed against the local API with the same borrower. Every task title is unique and the All count matches.

## Open items
- Not committed.
