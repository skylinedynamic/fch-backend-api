# Declined "Pay Now" attempts recorded as FAILED payments (BUG-035)

Date: 2026-10-26

## Problem
Partner portal: Wanda › Invoices › Pay Now ($800, INV-20261015007) with the declined test card 4000 0000 0000 0002 left no row in Payments. Another invoice (INV-20261015005) did get a FAILED row. The two payment paths behaved differently:
- The auto-charge paths (`ChargeSubscriptionInvoiceJob`, `UnifiedPaymentService::chargePartnerCardAutomatically`) record a FAILED row.
- Manual checkout, `UnifiedPaymentService::processPayment()`, logged the error and rethrew it without recording anything.

## Solution
- `processPayment()` now records one FAILED payment per declined attempt through a new `recordCheckoutFailure()`. It covers both decline shapes:
  - Stripe's `CardException` (HTTP 402). Its decline code is stored in `details`.
  - A charge that comes back unsuccessful (e.g. `requires_payment_method`). The PaymentIntent id becomes the transaction id.
- The row carries invoice, payer, amount, provider, card type, and `details` = `{source: checkout, error, code/decline_code}`.
- The invoice stays Unpaid, and the payer still sees the same error message.
- Nothing is recorded in these cases:
  - The payment is refused before the gateway is called (invoice not unpaid, unauthorized, etc.).
  - 3-D Secure `requires_action`.
  - A manual or bank payment.
- The row is written outside the payment transaction. A failure to record it is logged and never hides the decline.
- `ChargeSubscriptionInvoiceJob::stopAfterTooManyFailures()` (BUG-005's six-attempt stop) now ignores FAILED rows marked `source = checkout`. A payer retrying by hand therefore does not switch off auto-charge.
- New constants: `Payment::DETAIL_FAILURE_SOURCE`, `Payment::FAILURE_SOURCE_CHECKOUT`.

## Files changed
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: catches `CardException` around the charge and records unsuccessful charges; new `recordCheckoutFailure()`.
- `app/Models/Payment/Payment.php`: the failure-source constants.
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`: the six-failure count excludes checkout failures.
- `tests/Support/FakeStripeHttpClient.php`: `willFail($resource, $status, $error)` answers an endpoint with a Stripe error.
- `tests/Integration/Payment/CheckoutDeclineRecordedTest.php`: new. Covers a declined card, one row per attempt, an unsuccessful charge, and a refusal before charging.
- `tests/Integration/Payment/AutoChargeStopTest.php`: new case. Checkout failures do not count towards the six.
- `tests/Integration/Payment/PaymentTransactionBoundaryTest.php`: `a_declined_charge_records_nothing` became `a_declined_charge_records_only_the_failed_attempt`. Its old expectation (no rows) was the behaviour this bug changes. The invoice is still Unpaid, only the FAILED row exists, and no transaction is left open.

## Testing
- New tests failed first (3 failures, 1 error), then passed.
- `tests/Integration/Payment` + `tests/Feature/Payment`: all green after the update above.
- `--filter "Payment|Invoice|Billing|Subscription|Charge"`: 559/559 passed.
- The local MySQL was down, so the stage data (INV-...005/007) was not inspected; the cause was confirmed from the code.

## Open items
- The Payments KPI `total_payments` (admin `PaymentService` and borrower `PaymentService`) sums every status, FAILED included. This predates this change, since auto-charge failures were already counted, but checkout failures now add to it too. Consider restricting it to completed and pending statuses.
- A borrower's own declined checkout now also shows as a FAILED row in the client portal's Payments list. That list reads borrower payments regardless of status.
