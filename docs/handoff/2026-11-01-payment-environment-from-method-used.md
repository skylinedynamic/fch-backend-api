# Payments are recorded in the environment of the payment method used

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
A partner paid its enrollment with **Cash**. The host's Cash method was set to **Production**, but the payment showed as **Sandbox** in Payments (local DB: payment #95).

No path that records a payment said which environment it was in. `Payment::createRecord()` guessed with `getEnvironmentForPartner()`, which got it wrong in two ways:
- it read the **Stripe** mode of the payment's `partner_id` and never looked at the method actually used, so Cash, Bank Transfer and the reference-number gateways followed Stripe;
- it looked at the payer rather than the receiver. A white-label partner paying the host took the partner's own Stripe mode. The auto-charge charged on the host's Stripe but was recorded under the partner's.

Seven places record a payment: Pay Now, Pay Now failure, auto-charge success, auto-charge failure, the borrower fee auto-charge (success and failure), and the commission payout.

## Solution
One rule: **the environment is the mode of the payment method the money was taken with (or the attempt was made with).** That method always belongs to the receiver.

| Where | Environment |
|---|---|
| Pay Now (Cash, Bank, Card…) and its failure record | the method chosen (`$partnerProvider`) |
| Cron auto-charge, success and failure | the receiver's method the charge goes through. Resolved at the start of the job, so a refusal before Stripe is reached (no saved card) is filed the same way |
| Borrower fee auto-charge, success and failure | the host's Stripe, which the partner's card is charged on |
| Commission payout (no gateway) | chosen by the host in the payout dialog. Required `environment` field (`sandbox` / `production`). The dialog starts on `default_environment` from the API: Production only when `APP_ENV=production`, Sandbox everywhere else (`PaymentProvider::defaultModeForApp()`) |

`createRecord()` no longer guesses. It throws `InvalidArgumentException` when `is_environment` is missing, so a new payment path that forgets it fails in tests. `getEnvironmentForPartner()` is removed.

When no method is set up (e.g. the host has no Stripe for a borrower fee auto-charge), the failure is filed as Sandbox: no live money could have moved.

Old payments are not corrected. The user decided against a data fix: for Cash and Cheque the mode at the time of payment was never saved and cannot be recovered.

## Files changed
- `app/Models/Payment/Payment.php`: `createRecord()` requires `is_environment`; new `environmentOf(?PartnerPaymentProvider)`; `environmentFromMode()` is now static; `getEnvironmentForPartner()` removed.
- `app/Models/PaymentMethod/PaymentProvider.php`: `MODES`, `defaultModeForApp()`.
- `app/Services/GlobalPayment/PaymentGatewayManager.php`: `findPartnerProvider()` (non-throwing); `resolvePartnerProvider()` uses it.
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: Pay Now, checkout failure and borrower fee auto-charge pass the environment.
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`: resolves the receiver's method up front; success and failure records pass its environment.
- `app/Http/Requests/Api/V1/FCH/Admin/Commission/ProcessPayoutRequest.php`: `environment` is required and must be in `PaymentProvider::MODES`.
- `app/Services/FCH/Admin/Commission/CommissionService.php`, `app/Services/FCH/Common/Commission/CommissionPayoutService.php`: `pay()` takes the environment; unpaid payouts carry `default_environment`.
- Tests: new `tests/Integration/Payment/PaymentEnvironmentTest.php` (10 tests); 4 new tests in `tests/Feature/CommissionPayout/CommissionPayoutTest.php`. Existing tests that record payments or pay out now pass an environment: `ReconcilePaidInvoicesCommandTest`, `AutoChargeStopTest`, `ChargeSubscriptionInvoiceQueueTest`, `CommissionViewerTest`, `CommissionPayoutPaymentEntryTest`, `CommissionDatesInViewerTimezoneTest`.
- Admin panel: see `Fch-admin-frontend-react/docs/handoff/2026-11-01-commission-payout-environment.md`.

## Testing
- New tests failed before the fix: all 10 in `PaymentEnvironmentTest` (every path recorded the wrong environment; `createRecord()` accepted a payment without one) and 4 in `CommissionPayoutTest` (no `environment` rule, no `default_environment`). All pass now.
- Payment, payout, commission, invoice and subscription suites: 338 passed.
- Full fch-api suite (with the approval fix from the same day): 4290 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- Auto-charge uses the receiver's **default** method (`resolvePartnerProvider($receiverId)` with no provider id). If that default is Cash or Bank Transfer, the off-session charge cannot work and fails each run until it stops after 6 attempts. This is unchanged by this fix and worth a separate look.
- Remaining items from the payment review: a duplicate invoice is raised while a manual payment awaits approval; a late seat renewal counts from the old end date; a card charge runs inside the approve transaction.
