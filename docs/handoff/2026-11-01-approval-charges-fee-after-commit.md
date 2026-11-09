# Approving a manual payment: the borrower fee is charged after the approval commits

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
When a White-Label or Certified partner approves its borrower's first manual (Cash / Bank Transfer) payment, the partner's saved card is charged the borrower fee on the host's Stripe. `PaymentService::approvePayment()` did that **inside its DB transaction**, before the subscription was advanced and the approval email was sent. If either failed, the whole transaction rolled back:
- the borrower's payment went back to Processing,
- the fee invoice went back to Unpaid and the fee's payment record disappeared,
- but the money stayed taken at Stripe.

The next approval or cron pass charged the partner again, and that charge had **no idempotency key**, so Stripe could not tell it was a repeat. Pay Now (`UnifiedPaymentService::processPayment()`) had already been fixed for exactly this. Approval had not.

## Solution
- `approvePayment()` records the approval (payment Succeeded, invoice Paid, subscription advanced) in the transaction and only decides which borrower fee is due. **After it commits** it charges the fee and sends the receipt. A failing receipt is logged, never thrown, so the approval stands.
- `UnifiedPaymentService::chargePartnerCardAutomatically()` sends an idempotency key. A retry replays the same charge instead of debiting the partner again. *Superseded later the same day* (`2026-11-01-borrower-fee-charges-saved-card.md`): the fee is now charged through the same `chargeOffSession()` as the cron, under the cron's key for that invoice, so the two paths share one key.

## Files changed
- `app/Services/FCH/Common/Payment/PaymentService.php`: `approvePayment()` charges the fee and mails after the transaction.
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: idempotency key on the borrower fee PaymentIntent.
- `tests/Integration/Payment/ApprovalTransactionBoundaryTest.php` (new, 4 tests): the fee is charged with no transaction open; a failing approval email leaves the approval and the fee Paid; the fee charge carries an idempotency key; an ordinary approval still approves and charges the fee on the host's key.

## Testing
- Three tests failed before the fix: the mail exception rolled the approval back, the charge ran inside the transaction, and there was no key. All pass now. The ordinary-approval test passed before and after.
- `tests/Integration/{Payment,Subscription}`, `tests/Feature/{Payment,Billing,Subscription,Invoice,User}`: 403 passed.
- Full fch-api suite (with the same day's other payment fixes): 4304 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- With the idempotency key, a fee declined on a card is replayed as declined if retried on the same card within 24 hours (Stripe keeps keys for 24 h). The cron runs every 12 h, so one retry may replay the decline. A different card is a different key.
