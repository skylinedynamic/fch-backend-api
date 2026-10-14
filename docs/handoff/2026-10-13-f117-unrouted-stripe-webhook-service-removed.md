# Unrouted Stripe webhook service removed (F117, was O51)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F117 (was O51)

## Problem
`App\Services\GlobalPayment\Stripe\StripeWebhookService` had no route and no caller. It handled only `payment_intent.succeeded` and `payment_intent.payment_failed`. Had it been routed, three things were wrong with it:
- **No signature check.** It did not verify Stripe's signature, so anyone could have posted a fake "payment succeeded" and had an invoice marked Paid.
- **Duplicated work.** The app already records those outcomes when it charges, because PaymentIntents are confirmed synchronously. Handling them again would have done the work twice.
- **Conflicting rule.** It set subscriptions Past Due on failure. That decision belongs to `SubscriptionService` alone (see the note in `ChargeSubscriptionInvoiceJob::markFailed()`).

## Solution
**Decision (user):** remove it if unused, and no webhook feature for now.

- Deleted `app/Services/GlobalPayment/Stripe/StripeWebhookService.php`.
- Nothing referred to it. This was checked across `app/`, `routes/`, `config/`, `bootstrap/`, `database/` and `tests/`.
- No behaviour changes.

## Files changed
- `app/Services/GlobalPayment/Stripe/StripeWebhookService.php` (deleted)
- `tests/Feature/General/DeadRoutesTest.php`

## Tests
- `Feature/General/DeadRoutesTest::the_unrouted_stripe_webhook_service_is_gone` checks that the class is gone and that `StripeCustomerService`, which sits beside it, is still there. It failed first, then passed.
- Suites run: fch-api full suite: 2302 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- **O51 is closed with this fix** (decision: no webhook feature for now). Events that happen only at Stripe still never reach the app:
  - refunds made in the Stripe dashboard (the invoice stays Paid);
  - disputes and chargebacks (access stays on, and an unanswered dispute is lost by default).
- **Building it later needs:**
  - a per-partner endpoint and webhook signing secret, because each white-label partner has its own Stripe account;
  - signature verification;
  - an event log, so a repeated event is not applied twice;
  - handling for `charge.refunded` and `charge.dispute.*`;
  - decisions on what a refund or dispute does to the invoice and to access.
- **Until then:** a refund given in the Stripe dashboard has to be reflected in the app by hand.
- Run `composer dump-autoload` after pulling.
