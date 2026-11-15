# A client's tier can no longer be changed through the client update API

Date: 2026-11-16

## Problem

Found while checking R3-BUG-035. Capital Access's pre-flight checklist showed "EIN Verified" complete for client 216.

The checklist does not read the profile EIN. It reads Tier 0's "EIN Consistency & Identity Verification" task. A client past Tier 0 counts as having that task done, because Capital Access needs Tier 0 complete, and Tier 0 complete means that task is complete. That reasoning only holds if the tier moves **only** when tasks are verified.

It did not. The client update (`POST /api/v1/common/borrowers/{id}`) accepted `current_tier`:
- `UpdateBorrowerRequest` had `'current_tier' => ['nullable']`, with no other check.
- `BorrowerService::update()` saved it. `Borrower` has `$guarded = []`.

So any host or partner who may edit a client could move that client past Tier 0 with one API call, without its tasks. Sending `null` cleared the tier. No portal form sends the field; the admin portal only uses `current_tier` as a client-list filter, which goes through the separate `FetchBorrowerRequest`.

## Solution

`current_tier` is no longer saved by the client update, in two places:
- It is removed from `UpdateBorrowerRequest`'s rules, so it is not in `validated()`.
- It is added to `BorrowerService::update()`'s except list as a second guard.

A sent value is ignored and the rest of the update saves. This is the same approach as a client's free-plan end date.

The tier now moves only by verifying tasks, in `TaskVerificationService` and `VerificationCenterService`. Create still starts every client on the first tier.

## Files changed

- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerRequest.php`: `current_tier` rule removed.
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `current_tier` added to the update's except list.
- `tests/Feature/Borrower/ClientTierNotEditableTest.php` (new, 4 tests):
  - the host cannot move a client to another tier
  - the partner cannot move its client to another tier
  - `null` does not clear the tier
  - the rest of the update still saves

## Testing

- **Seen failing first:** all 4. The tier moved 1 → 2, and `null` cleared it.
- **After the fix:**
  - new tests: 4 passed
  - `tests/Feature/Borrower`: 338 passed
  - `tests/Feature/CreditBuilder`: 134 passed
  - `tests/Feature/Profile`: 106 passed
  - `tests/Integration/CreditBuilder`: 60 passed
- **Full suite:** see the PR / session notes.

## Open items

- **R3-BUG-035 itself:** by the rule above, "EIN Verified" following Tier 0 is by design, not a bug. QA judged it by the empty profile EIN. To confirm client 216 left Tier 0 by verifying its tasks, check its `borrower_task_verification_requests` for task_id 1 on stage. If it was moved through the API, this change closes that path; its existing tier was not changed.
- **Still open from R3-BUG-035:** `POST strategies/{id}/start` answers 200 while the checklist is incomplete, and the roadmap then answers 403. Not changed here.
- **Checked and not a bug:** "the tier count counts verified requests, so one task verified twice counts twice". Every path refuses or reuses a second request for an already verified task:
  - client submit: "Task already completed."
  - admin verify: updates the latest request
  - Verification Center: skips a decided request
