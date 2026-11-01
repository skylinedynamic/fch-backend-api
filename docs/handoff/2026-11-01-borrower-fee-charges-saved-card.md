# Borrower fee charged at once from the partner's saved card

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
When a White-Label or Certified partner's borrower makes a first card payment, or the partner approves the borrower's manual payment, the borrower fee is charged from the partner's card into the host's Stripe account (`UnifiedPaymentService::chargePartnerCardAutomatically()`).

That code looked for the card in the partner's **past successful card payments**. The cron (`ChargeSubscriptionInvoiceJob`) charges the card **saved on the partner** in My Subscription. My Subscription saves the card on the partner's Stripe customer (`stripe_customer_id`, default card) and creates no payment record. So a partner that paid its own enrollment in cash and later added a card failed at once (a Failed row), and waited up to 12 hours for the cron to take the fee. Its borrower stayed locked (`partner_payment_for_me = false`) meanwhile.

## Solution
The immediate charge now does what the cron does: an off-session charge of the partner's saved Stripe customer on the host's active Stripe, through `StripePaymentDriver::chargeOffSession()`. It uses the same idempotency reference as the cron (`invoice_{id}`). If a charge goes through but is not recorded, the cron's retry replays it instead of taking the fee twice.

Unchanged:
- the partner's default payment method must be Stripe (`2026-11-01-auto-charge-payer-default-stripe.md`);
- the fee is paid into the host's Stripe account, whatever the host's default;
- environment and failure records are as before.

## Files changed
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: `chargePartnerCardAutomatically()` charges the saved customer via `chargeOffSession()`; unused `Stripe\PaymentIntent` and `Stripe\Stripe` imports removed.
- `tests/Integration/Payment/AutoChargeDefaultMethodTest.php`: a card saved only in My Subscription pays the fee at once; the immediate charge and a cron retry of the same fee send the same idempotency key.
- `tests/Integration/Payment/ApprovalTransactionBoundaryTest.php`: the key assertion no longer expects a `borrower_fee_` prefix (it is now the cron's key).

## Testing
- Both new tests failed before the change (no charge without a past card payment, so no key), then passed.
- `tests/Integration/{Payment,Subscription}`, `tests/Feature/{Payment,Billing,Subscription,Invoice}`: 358 passed.
- Full suite: see the session / PR.

## Open items
- Not committed; the target branch is to be confirmed.
