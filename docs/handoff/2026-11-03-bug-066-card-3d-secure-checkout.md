# BUG-066: 3-D Secure (OTP) cards can pay, and every attempt is recorded

**Date:** 2026-11-03
**Branch:** bugfix/round-2
**Author:** Awlad Hossain

## Problem
QA's report (R2-OP-PAY-03/04, R2-CP-INV-02): a client paying an invoice by card (Pay Now) left no payment record when the attempt failed.

- **Declined card (4000 0000 0000 0002).** Already fixed by BUG-035 (`recordCheckoutFailure()`, 26-Oct). QA tested a build from before that fix.
- **3-D Secure card (4000 0027 6000 3184).** `processPayment()` threw "requires verification from your bank, which is not supported yet". It recorded nothing, and these cards could not pay at all. The flow had been left out on purpose (see the old `CardAuthenticationRequiredTest` docblock).

## Solution
A complete 3-D Secure checkout. No webhook is needed: each partner has their own Stripe account, so a webhook would need a signing secret per tenant. Instead, the API reads the outcome from Stripe itself.

1. **Pay Now.** Stripe answers `requires_action`. `processPayment()` keeps the attempt as a **PENDING** payment and returns `requires_action`, `client_secret` and `payment_id`. HTTP 200, message `payment.authentication_required`. In the row's `details`:
   - `awaiting = authentication`;
   - `source = checkout`;
   - `partner_payment_provider_id`: the gateway account, so the payment is read back from the same Stripe account.

   The client secret is not stored.
2. **The browser** runs the bank's challenge (`stripe.handleNextAction`).
3. **`POST …/process-payment/confirm` `{payment_id}`.** `confirmAuthenticatedPayment()` checks that the caller may pay the invoice (same rule as paying), reads the PaymentIntent from Stripe, and settles **the same row**:
   - **succeeded:** the normal success path runs (invoice Paid, subscription, platform fee, mail);
   - **refused by the bank:** FAILED, and 400 with a plain message. Stripe's developer wording is kept in `details.gateway_error`. A decline after a passed challenge keeps the bank's reason;
   - **still waiting:** stays PENDING, message `payment.authentication_pending`.

   Calling confirm again does no harm: a settled payment is reported as it stands.
4. **Tab closed mid-challenge.** `payments:settle-card-authentications` runs every 5 minutes. It records payments that were authenticated, and fails the ones the bank refused. After `Payment::AUTHENTICATION_TIMEOUT_MINUTES` (30) it cancels a payment still waiting, at Stripe, and marks it FAILED. One error does not stop the rest.
5. **Double-charge guards:**
   - **The auto-charge cron** (`ChargeSubscriptionInvoiceJob`) skips an invoice that has an open authentication.
   - **A new Pay Now attempt** first settles any open challenge on that invoice. If it is still waiting, it is cancelled ("Replaced by a new payment attempt…"). If it was completed in another tab, it is recorded, and the new attempt is refused as already paid.
   - **Cancel race:** if Stripe refuses a cancel because the payer has just completed the challenge, the payment is read again and recorded.
   - **Invoice already paid** another way when the authenticated charge lands: the charge is recorded as SUCCEEDED with `duplicate_charge`, and a `Log::critical` asks for a refund.

`processPayment()` was split. The post-charge recording moved, unchanged, to `completeCharge()`, so a normal charge and a settled 3-D Secure charge share one path. Gateways other than Stripe do not implement the new `AuthenticatesCardPayments` contract. For them, `requires_action` still gives the old message. The off-session cron path is unchanged (F116 still mails the payer).

**Routes (all go to `UnifiedPaymentController::confirmPayment`):**
- `POST /api/v1/borrower/process-payment/confirm`
- `POST /api/v1/process-payment/confirm`
- `POST /api/v1/payment/confirm`

## Files changed
- `app/Contracts/Payment/AuthenticatesCardPayments.php` (new): `retrievePayment()` / `cancelPayment()`.
- `app/Services/GlobalPayment/Drivers/StripePaymentDriver.php`: implements the contract; intent-status constants; `describeIntent()` leaves out the client secret.
- `app/Services/GlobalPayment/UnifiedPaymentService.php`:
  - `processPayment()` now returns the hand-off;
  - `completeCharge()` extracted;
  - new methods: `assertMayPay()`, `awaitAuthentication()`, `confirmAuthenticatedPayment()`, `settleAwaitingAuthentication()`, supersede, cancel, fail helpers.
- `app/Http/Controllers/Api/V1/GlobalPayment/UnifiedPaymentController.php`: `confirmPayment()`; hand-off message.
- `app/Http/Requests/Api/V1/GlobalPayment/ConfirmPaymentRequest.php` (new).
- `app/Models/Payment/Payment.php`: awaiting constants, `isAwaitingAuthentication()`, `scopeAwaitingAuthentication()`, timeout constant.
- `app/Console/Commands/SettleCardAuthentications.php` (new); `routes/console.php`: every 5 minutes.
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`: skips an invoice that has an open authentication.
- `routes/borrower.php`, `routes/common.php`: the confirm routes.
- `lang/en/payment.php`, `lang/en/payment_validation.php`: new messages.

## Tests
- **Added `tests/Feature/Payment/CardAuthenticationCheckoutTest.php` (16 tests).** Covers:
  - the hand-off;
  - the client and host see the attempt;
  - the client secret is not stored;
  - success pays the invoice;
  - failure is FAILED with a plain message;
  - a decline keeps the bank's reason;
  - pending stays pending;
  - confirming twice settles once;
  - another client cannot confirm;
  - only an awaiting payment can be confirmed;
  - validation and auth;
  - all three portals' URLs;
  - a new attempt cancels the open one;
  - a new attempt after the open one went through is refused.
- **Added `tests/Feature/Payment/SettleCardAuthenticationsCommandTest.php` (8 tests).** Covers:
  - tab closed but authenticated: paid;
  - within the time limit: left alone;
  - stale: cancelled and FAILED;
  - completed at the last moment, cancel refused: recorded;
  - one error does not stop the others;
  - only awaiting payments are read;
  - scheduled every 5 minutes;
  - the cron does not charge an invoice mid-challenge.
- **Updated `tests/Integration/Payment/CardAuthenticationRequiredTest.php`.** The two tests that pinned the old "throw, record nothing" behaviour now expect the hand-off and a PENDING row. The decline and auto-charge tests are unchanged.
- **Updated `tests/Integration/Console/ScheduleTest.php`.** The new command is in the expected schedule.
- **Updated `tests/Support/FakeStripeHttpClient.php`.** New `on(method, path, ...bodies)` / `requestsTo()`, so a test can answer creating, reading and cancelling a PaymentIntent separately.
- **Red first:** 15/15 and 8/8 failed before the code. The plain-message test also failed first.
- **Run:**
  - `tests/Feature/Payment`, `tests/Integration/Payment`, `tests/Feature/Billing`: 366 passed.
  - Full suite: 4546 passed, 2 skipped, 4 failed. 2 were in `ScheduleTest` (fixed above). 2 are in `SafeUploadTest`, which fails without these changes too: `enshrined/svg-sanitize` is in `composer.json` but missing from local `vendor/`, so run `composer install`.
- **E2E (client-fch, real Stripe sandbox):** `e2e/sidebar/invoices/invoice-3ds-payment.spec.ts`, Fail and Complete both pass. The local DB showed:
  - superseded attempt: FAILED;
  - failed challenge: FAILED;
  - completed challenge: SUCCEEDED, invoice Paid.

## Open / follow-up
- **Deploy order.** The API, client-fch and Fch-admin-frontend-react must go out together. An old portal build would show "Payment completed" on a hand-off: the invoice stays Unpaid, and the command expires the attempt after 30 minutes.
- **The scheduler must be running** (`schedule:run` every minute) on stage and production, or tab-closed payments stay PENDING until the payer tries again.
- **`duplicate_charge` payments need a manual refund.** Watch for the `Log::critical` line. One known way to get one: a manual (Cash / Bank Transfer) payment is submitted while a card challenge is still open on the same invoice. Manual payments do not supersede an open challenge, so completing that challenge afterwards charges the card too.
- **Off-session (cron) 3-D Secure still cannot succeed.** Cards are saved with a plain PaymentMethod, not a SetupIntent, so the bank may challenge every auto-charge. Saving cards through a SetupIntent (off-session mandate) is the follow-up if this shows up in the logs.
- **Pre-existing, not changed here:** a normal successful charge stores the full PaymentIntent, including `client_secret`, in `payments.details`.
