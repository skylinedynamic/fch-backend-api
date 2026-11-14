# R3-BUG-028: a funding request is approved only with a lender, and assign-lender needs one

Date: 2026-11-15

## Problem

QA client 216, request 83 (Pending, `lender_ids [1,2]`):
- **No lender sent.** `POST common/funding-request/assign-lender {id:83}` with no lender answered 200 "Lenders assigned successfully" and set `lender_ids` to `[]`. The matched lenders were lost.
- **Approved with no lender.** `POST common/funding-request/status-update {id:83, status:'Approved'}` answered 200. The request was Approved with `assign_lender: null`. Request 88 was approved the same way with no assign call at all.

Causes:
- The UI asks for a lender before the host approves (`FundingRequestDetail.tsx`, "Lender Required" modal). The API didn't.
- Assign-lender took `lender_ids` as `nullable|array` and saved `$data['lender_ids'] ?? []`. The UI always sends exactly one lender.

**Decision (2026-11-15):** approving a funding request needs a lender, whoever approves it.

## Solution

- **assign-lender:** `lender_ids` is `required|array|min:1`. Sent missing or empty, it gets 422 and nothing changes.
- **status-update to Approved:** refused with 422 "Assign a lender before approving this funding request." (`funding_request.lender_required_to_approve`) while no lender is assigned.
  - This applies to the host and to partners.
  - The check runs under the same row lock as the stale-page check. Nothing is written and nobody is notified.
  - Rejecting, or any other status, needs no lender. A request already Approved is not re-checked.
- **Admin:** no change. Only the host sees the status and lender cards, and the host's Approve already asks for the lender first.

## Files changed

- `app/Http/Controllers/Api/V1/FCH/Common/FundingRequest/FundingRequestController.php`: `lender_ids` is required, with at least one.
- `app/Services/FCH/Common/FundingRequest/FundingRequestService.php`: `fundingRequestStatusUpdate()` refuses Approved without a lender.
- `lang/en/funding_request.php`: `lender_required_to_approve`.
- `tests/Feature/FundingRequest/FundingRequestApproveNeedsLenderTest.php` (new, 5):
  - assign-lender with no lender is refused and the matched lenders are kept
  - the host can't approve without a lender
  - a partner can't either
  - with a lender assigned it is approved
  - rejecting needs no lender
- **Updated to the decision:** these approved requests with no lender, so they now give the request a lender first:
  - `tests/Feature/FundingRequest/FundingRequestReviewApiTest.php`: 3 approve tests, through `withAssignedLender()`
  - `tests/Feature/FundingRequest/FundingRequestStatusNotificationTest.php`: the request fixture has a lender

## Testing

- **Seen failing first:** 3 of the 5 (the empty assign, the host approve, the partner approve). The other 2 pin behaviour that stays.
- **`tests/Feature/FundingRequest`, `Application`, `General`:** 275 passed.
- **Full suite:** see below.

## Open items

- **Stage data:** requests 83, 87 and 88 were approved with no lender during QA. They are left as they are, since Approved → Rejected is refused.
- **Partners and lenders:** partners can't assign a lender in the UI (the lender card is host-only). A partner's approval therefore now depends on the host assigning the lender first.
