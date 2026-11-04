# Rejected funding request: "Update Application" resubmits it in place

Branch: `qa-round2-bugfix` in `fch-backend-api` and `fch-borrower-react`. Finding: F230 (amends F131).

## Problem
- "Update Application" on a Rejected funding request (list, detail page, modal) opened `/funding/:id/review` with only the amount and purpose. Every submit created a **new** `funding_requests` row (the F131 "always new" rule) and left the rejected one as it was. Clicking it twice gave Rejected + Pending + Pending for what the borrower saw as one request.
- On the Documents step, the optional "Upload Again" button on already-uploaded documents only showed when `latest_review_status` (the newest request on that funding option) was Rejected. After any later request it was Pending, so the borrower had no way to add updated documents (e.g. newer bank statements).

## Solution
User decision 2026-11-04: **rejected only**. Updating a rejected request changes that request. "Start Funding Request" from a funding option still creates a new request (F131 otherwise unchanged).
- API
  - `StoreFundingRequestRequest`: new optional `funding_request_id`. It is accepted only for the borrower's own **Rejected** request on the **same** `funding_sequence_id`; anything else is a 422 on `funding_request_id`. Once resubmitted the request is Pending, so a second submit (double click / second tab) is refused. Messages: `funding_request.resubmit_*`.
  - `FundingRequestService::store()`: with `funding_request_id`, inside a transaction with `lockForUpdate`, it re-checks Rejected and updates the row (amount, purpose, documents, lenders, status Pending) and clears `reject_reason`, `approved_at` and `approved_by`. The host/partner "submitted" notification is sent again. The activity log records "Funding Request Resubmitted" (`LOG_TYPE_FUNDING_REQUEST_STATUS_UPDATE`) with the previous rejection reason.
- Borrower portal
  - `FundingRequestList.tsx`, `FundingRequestDetailPage.tsx`, `FundingRequestModal.tsx`: "Update Application" opens `/funding/:id/review?resubmit=<request id>` (and passes `fromRejectedRequestId` in router state as a fallback). The URL param came after a first version carried the id only in router state: a wizard tab opened by an earlier click had no id and inserted request #77.
  - `FundingRequestWizard.tsx`: sends `funding_request_id` when `?resubmit` (or the state) is present. Step 1 shows "You are updating your rejected funding request ... it does not create a new one", and the button reads "Resubmit for Underwriting". The Documents step shows a note ("Your previous request was rejected ... Uploading more is optional") and "Upload Again (Optional)" on each uploaded document type. Submitting without uploading stays allowed.

## Tests
- `tests/Feature/FundingRequest/ReapplyAfterSettledApplicationTest.php`: updating a rejected request resubmits that row and inserts none; a not-rejected request (incl. a second submit) is refused; another borrower's request is refused; another funding option is refused. The existing "rejected is kept and a new one created" test still holds for a submit without `funding_request_id`.
- Borrower `tests/pages/funding/FundingRequestWizard.test.tsx`: no `funding_request_id` on a fresh request; it is sent when updating a rejected one; the optional upload + note show only when updating a rejected request. `tests/test-utils.tsx` `route` now accepts `{ pathname, state }`.
- Run: `php artisan test tests/Feature/FundingRequest/` (76 passed); borrower `vitest run tests/pages/funding tests/pages/funding-requests` (67 passed).

## Left open
- The duplicate rows already created in local testing (borrower 1: #75, #76, #77 Pending next to the rejected #74) are not merged or deleted. Cancel them in the admin panel if needed.
- `/funding/:id/review` without `?resubmit` starts a new request, as designed.
- There is no component test that the three "Update Application" links pass `fromRejectedRequestId` (one-line state additions).
