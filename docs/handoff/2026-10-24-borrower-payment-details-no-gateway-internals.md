# Borrower payment details — no gateway internals (BUG-062)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-062** (CP-PAY-09): the client portal's payment detail (Payments › payment 189 › Verification Info) listed the whole Stripe PaymentIntent — **client_secret** (`pi_…_secret_…`), customer id, latest charge id, amount in cents, a unix timestamp, raw JSON.

`Borrower\Payment\PaymentResource` returned the whole `details` column as `raw_details`. For a card payment that column is the gateway's PaymentIntent (`UnifiedPaymentService` stores `$paymentIntent->toArray()`); for a failed one, the error plus the `input_data` sent to the gateway. The portal printed every key. The sheet had it as Fixed, but no fix was on any branch.

## Solution
`raw_details` for a borrower is now an **allow-list** (`BORROWER_DETAIL_FIELDS`) of plain values: `provider`, `bank_name`, `account_name`, `account_number` (sent as `****` + last four), `instructions`, `transaction_number`, the approval / rejection fields (`decision`, `note`, `approval_note`, `approved_at`, `approved_by`, `reason`, `rejection_reason`, `rejected_at`, `rejected_by`) and a failed payment's `error`. Everything else — the PaymentIntent, `input_data`, `routing_number`, `bank_detail_id`, `*_by_id`, `mode` — stays server side. A card payment's `raw_details` is now `[]`, so the portal shows no Verification Info for it. Applies to both the list and the single payment.

The portal side (only named fields printed) is in client-fch's handoff of the same date.

## Files changed
- `app/Http/Resources/Borrower/Payment/PaymentResource.php` — `BORROWER_DETAIL_FIELDS`, `borrowerDetails()`.
- `tests/Feature/Payment/BorrowerPaymentDetailsExposureTest.php` — new, 5 tests.

## Tests
- New (all 5 failed first, pass after): a card payment sends no PaymentIntent (no `secret`, customer or charge id in the response); the list neither; an approved cash payment keeps its readable fields with the account masked and internal ids dropped; a rejected one keeps who and why; a failed one keeps only its error.
- `tests/Feature/Payment`: 134 passed. Full suite: 3463 passed, 2 skipped.

## Open items
- The partner portal's resource (`FCH\Common\Payment\PaymentResource`) still returns the whole `details` to partner/host users. Not the client, so out of this bug's scope — but a gateway client_secret has no reason to reach any browser; worth the same allow-list.
- Stored rows are unchanged; only what is sent changed.
- Mark BUG-062 in the QA defect log In Progress, then Fixed after the stage retest.
