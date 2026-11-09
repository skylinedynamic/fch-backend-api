# BUG-034 duplicate verification requests; BUG-040 stale value on a second change approval

**Date:** 2026-11-02
**Author:** awlad

## Problem
- **BUG-034** (R2-CP-CBG-01, Client › Credit Builder › Step-By-Step Guide):
  - A double-click on "Submit for Verification" sent two `POST /borrower/credit-builder/task-verify-request` requests, and both returned 200.
  - The host got two *processing* rows for one task. The Verification Center showed "2 Pending", and the borrower's detail listed the task twice.
  - After one was rejected, the client saw "Verification Rejected" while the other was still in review.
  - Cause: the service refused only a task that was already *verified*, not one already in review.
- **BUG-040** (R2-OP-CHG-02, Owner › Intake & Review › Change Requests):
  - Two pending requests changed the same field (business phone 0188, then 0199), both filed while the phone was 0122.
  - After 0188 was approved, the review of 0199 still showed *Current value* 0122.
  - Both history rows kept 0122 as the old value.
  - Cause: the review modal's "current value" is the request's stored `old_value`, captured when the request was filed and never updated.

## Solution
- **BUG-034:** `CreditBuilderStepGuideService::taskVerifyRequest()`:
  - Locks the borrower row for the submission (`lockForUpdate`), so two requests at the same moment are handled one after the other.
  - A task with a request already *processing* (same tier and migrated task) answers with **that request** instead of opening a second one. A repeat therefore gets 200 with the existing request, so a double-click shows no error.
  - A *rejected* request does not count, so "Submit Again" still opens a new one.
  - The host is notified only about requests this call actually opened. An empty list sends nothing; the notifier already skips that case.
- **BUG-040:** `ChangeRequestService::updateStatus()`:
  - After an approved change is applied, the new private method `rebaseOtherPendingRequests()` sets the `old_value` of every other *pending* request for the same borrower, field and entity to the value just applied.
  - The reviewer then sees the real current value, and the history records what each approval actually replaced.
  - It goes through the model, so an SSN stays encrypted.
  - Skipped for a sign-in email change, which is not applied until the new address is confirmed.

## Files changed
- `app/Services/Borrower/CreditBuilder/CreditBuilderStepGuideService.php`: the lock, the in-review check, and notifying only opened requests.
- `app/Services/FCH/Common/ChangeRequest/ChangeRequestService.php`: `rebaseOtherPendingRequests()`, called after an approval is applied.

## Testing
- **`tests/Feature/CreditBuilder/BorrowerCreditBuilderApiTest.php`:**
  - New: `a_task_already_in_review_is_not_submitted_a_second_time`. Two submissions give the same request id and one processing row. After a rejection a new request is opened. It failed first (two different ids).
- **`tests/Feature/Borrower/ChangeRequestReviewApiTest.php`:**
  - New: `approving_one_change_updates_the_current_value_of_the_others_for_that_field`. It failed first: 'Old Co' where 'First Co' was expected.
- **Run:**
  - `tests/Feature/CreditBuilder`: 122 passed.
  - `tests/Feature/Borrower` + `tests/Feature/EmailChange` + `tests/Unit/Profile`: 397 passed.
  - The full suite was not run.

## Open items
- Nothing changed in the portals: the client's button was already disabled while sending, and the admin modal reads `old_value`, which is now correct.
- A request filed before the field was changed by some other route (not through a change request) still shows the value at filing time. Only approvals through the queue update the other pending requests.
