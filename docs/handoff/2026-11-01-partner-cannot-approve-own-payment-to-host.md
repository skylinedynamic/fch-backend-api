# A partner can no longer approve its own payment to the host

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
A White-Label or Certified partner could approve or reject the **borrower fee** it owes the host. The partner submitted it by Cash or Bank Transfer (payment Processing), then pressed Approve itself. The invoice went to Paid and the host was recorded as paid without receiving anything.

Cause: `Payment::canApproveForUser()` let a non-host partner decide any payment whose `partner_id` was its own and that had a `borrower_id` or a Borrower payer. A borrower fee payment has `partner_id` = the partner and carries the borrower's id, so it passed. Confirmed against the local DB: WL partner #6 got `true` for its own borrower fee payment.

`canApproveForUser()` decides both the approve/reject endpoints (`PaymentService::approvePayment()` / `rejectPayment()`) and the `can_approve` flag the admin panel uses to show the Approve/Reject buttons (`PaymentResource`, `InvoiceResource`).

## Solution
Only whoever receives the money may confirm it. A non-host partner may now decide a payment only when it is its own (`partner_id`), the payer is a Borrower (`entity_type = Borrower`), and it is not partner-paid (`is_partner_paying = false`). Everything a partner pays the host (enrollment, installment, platform fee, seat, borrower fee) is the host's to decide. The host rule is unchanged.

## Files changed
- `app/Models/Payment/Payment.php`: `canApproveForUser()` non-host rule.
- `tests/Feature/Payment/PartnerInvoicesAndPaymentsTest.php`: `borrowerFeeInvoice()` helper and 5 tests. A WL and a Certified partner cannot approve their borrower fee, a partner cannot reject it, a partner gets `can_approve: false`, and the host gets `can_approve: true` and approves it.
- `Fch-admin-frontend-react/tests/pages/payments/PaymentDetails.test.tsx`: a Processing payment with `can_approve: false` shows no Approve/Reject buttons. No frontend code change was needed, since the buttons already follow the API flag.

## Testing
- The 4 new partner tests failed before the fix (200 instead of 422, `can_approve` true), then passed. The host test passed before and after.
- `tests/Feature/Payment`, `tests/Feature/Invoice` and `BorrowerCommonRouteAccessTest`: 206 passed.
- Full fch-api suite (with the payment environment fix from the same day): 4290 tests, 0 failures, 2 skipped. An earlier run had one failure in `ActivityLogTaskAssignmentTest::the_due_date_may_be_later_today_but_not_earlier`. That test depends on the time of day; it passes on its own with and without this change.
- Admin Vitest `tests/pages/payments` and `tests/pages/invoices`: 58 passed. The new frontend test passed from the start, because it is a characterization test of existing behaviour.

## Open items
- Production data: check whether any payment a partner made to the host was approved by that partner's own user. The query is in the session notes. The local DB has none.
- Not committed. The target branch is to be confirmed.
- The rest of the payment review (Sandbox environment for manual payments, duplicate invoice while a manual payment awaits approval, late seat renewal, card charge inside the approve transaction) is tracked separately.
