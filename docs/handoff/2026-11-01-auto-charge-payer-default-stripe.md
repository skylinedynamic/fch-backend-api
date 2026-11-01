# Auto-charge: only payers whose default is Stripe, through the receiver's Stripe

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
The cron auto-charge (`ChargeSubscriptionInvoiceJob`) picked the gateway from the **receiver's default** payment method (`resolvePartnerProvider($receiverId)`). If the receiver's default was Cash or Bank Transfer, or it had no default and Cash came first (local DB: partners #35 and #41), the job:
- checked the saved card's mode against Cash's mode;
- then asked for a "driver" for Cash and threw `Unsupported payment gateway driver: Cash Payment`, outside the job's error handling.

So there was no failed payment, no notice, and no stop after 6 attempts. It was retried twice a day for ever, and the payer's card was never charged.

The payer's own choice was also ignored: a borrower or partner whose default method was Cash still had a saved card charged.

## Solution
Rule (confirmed by the product owner):
- **Payer:** auto-charged only when their `default_payment_method` is **Stripe**. Otherwise (another method, or none) they are not charged: a failed payment is recorded and they are told once (`PaymentFailedMail`, as for a missing card). The existing stop after 6 attempts applies.
- **Receiver** (host, WL or CP): its default is **not** checked. The charge goes through the receiver's **active Stripe**. If it has none, a failed payment is recorded and the admins are alerted (`markFailed`). No exception, so the 6-attempt stop applies.
- **Borrower fee** (partner → host, charged when the borrower pays): the partner's default must be Stripe. The partner's card is charged and the money is paid **into the host's Stripe account**, using the host's active Stripe keys whatever the host's default method is (unchanged). The host is never debited; it receives the fee.

## Files changed
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`: resolves the receiver's Stripe (not its default) up front; refuses a payer whose default is not Stripe; records a failure when the receiver has no Stripe.
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: `chargePartnerCardAutomatically()` refuses a partner whose default is not Stripe.
- `lang/en/payment.php`: `autocharge_default_not_stripe`, `autocharge_default_not_stripe_msg`.
- `tests/Integration/Payment/AutoChargeDefaultMethodTest.php` (new, 7 tests).

## Testing
- 6 of the 7 new tests failed before the fix. Three threw `Unsupported payment gateway driver: Cash Payment`, and three charged a card whose owner chose Cash. All pass now. The 7th (borrower fee on the host's Stripe whatever the host's default) passed before and after.
- `tests/Integration/{Payment,Subscription}`, `tests/Feature/{Payment,Billing,Subscription,Invoice,User}`, `tests/Unit/Payment`: 456 passed.
- Full fch-api suite (with the same day's other payment fixes): 4311 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- A payer whose default is not Stripe gets one Failed row per cron pass (twice a day) until the 6-attempt stop, then none. Only one email is sent. If those rows are unwanted in Payments, the dispatcher could skip such payers instead. Not done: the decision was "record a failure and tell them once".
