# Credit builder rejection keeps the reviewer's reason (BUG-083)

Date: 2026-10-27

## Problem
Owner › Verification Center › Reject Request with a reason (e.g. "phone not listed in directories"). The client never saw the reason, for two reasons in the API:
1. `VerificationCenterService::updateStatus()` saved the reviewer's note only on Completed. Every other outcome, a manual rejection included, was saved as "No matching business record found.", which is the automated MicroBilt check's wording.
2. `TaskOverviewItemResource` sent `rejection_reason` from a column that does not exist (the reason lives in `note`), so the client Overview always got null.

The reviewer's summary dialog also said "Verification failed." for a manual rejection.

## Solution
- A manual decision (verify or reject) saves the reviewer's `note`. The automated check's wording (`$errorMessage`, e.g. "No matching business record found.") is saved only when that check is what failed.
- The summary message for a manual rejection is "Rejected: <reason>", or "Rejected." with no reason. The automated-failure messages are unchanged.
- The Overview's `rejection_reason` is the request's `note` when the request is rejected, and null otherwise.

## Files changed
- `app/Services/FCH/Common/CreditBuilder/VerificationCenterService.php`: note and summary message per decision type.
- `app/Http/Resources/Borrower/CreditBuilder/TaskOverviewItemResource.php`: `rejection_reason` read from `note`.
- `lang/en/verification_center.php`: `rejected`, `rejected_with_reason`.
- `tests/Feature/CreditBuilder/VerificationRejectionReasonTest.php`: new. Covers the reason being saved, the summary message, and the reason on the client Overview.

## Testing
- The new tests failed first (3 failures), then passed.
- `--filter "Verification|CreditBuilder|MicroBilt|Tier|Overview"`: 246/246, including the existing MicroBilt no-match case, which still saves "No matching business record found."

## Open items
- Rejections saved before this change keep "No matching business record found." as their note.
