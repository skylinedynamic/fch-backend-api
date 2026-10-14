# Payer told when an auto-charge fails; unsent mail removed (F116, was O46)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F116 (was O46)

## Problem
Two mailables were never sent from anywhere:
- **`GlobalPaymentNotificationMail` ("New Payment Received")** was redundant. The success and submitted payment mails already cover that.
- **`PaymentFailedMail` ("Payment Failed")** exposed a real gap. When `ChargeSubscriptionInvoiceJob` could not take a payment, it recorded a failed payment and alerted the admins (`CRON_ERROR_NOTIFY_EMAILS`, when set). The **payer was never told**. This happened when the card was declined, when Stripe did not complete the charge, or when there was no saved card. Their unpaid invoices piled up until access was limited. Only the card-in-the-other-Stripe-account case had a payer email (`CardReauthorizationRequiredMail`).

## Solution
**Decision (user):** remove `GlobalPaymentNotificationMail` and start sending `PaymentFailedMail`.

**`ChargeSubscriptionInvoiceJob`**
- New `failAndTellPayer()` records the failure via `markFailed()`, which still sends the admin alert, and tells the payer **once per invoice**. The ledger entry carries `payer_told_of_failure`, and later passes see it. This mirrors how the card re-entry request is sent once.
- It is used for the failures the payer can act on:
  - **No saved Stripe customer.** The card was never saved, or was removed (F110).
  - **Stripe returned a charge that did not succeed**, including the 3-D Secure case.
  - **Stripe threw a `CardException`.** This is how a real decline arrives (a 402), so it is now caught separately from other exceptions.
- **Other exceptions** (gateway or system errors) stay admins-only, as before.
- **A card saved in the other Stripe account** keeps its own `CardReauthorizationRequiredMail` and does not also get `PaymentFailedMail`.

**`PaymentMailService::sendPaymentFailed($invoice, $noSavedCard)`**
- Works the same way as `sendCardReauthorizationRequired()`.
- The mail comes from the payer's own tenant (white-label or host branding).
- It sends both a bell and an email.
- New notification types: `borrower.payment.failed` (links to `/billing-details`) and `partner.payment.failed` (links to `/my-subscription`). Both are in `config/notifications.php`.

**`PaymentFailedMail` and `emails/payment/failed.blade.php`**
- New optional fields: `invoice_id`, `no_saved_card` and `portal_url`. `transaction_id` is now optional.
- The view shows the invoice number, and a transaction row only when there is a transaction id.
- With no saved card it uses a specific opening line: there is no default payment method on the account.
- It adds an "Update Payment Method or Pay Now" button to the billing page.

**Removed:** `app/Mail/Payment/GlobalPaymentNotificationMail.php`, `resources/views/emails/payment/global_notification.blade.php`, and its row in the mail render test.

## Files changed
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`
- `app/Services/GlobalPayment/PaymentMailService.php`
- `app/Mail/Payment/PaymentFailedMail.php`
- `resources/views/emails/payment/failed.blade.php`
- `config/notifications.php`
- `app/Mail/Payment/GlobalPaymentNotificationMail.php` (deleted)
- `resources/views/emails/payment/global_notification.blade.php` (deleted)
- `tests/Integration/Payment/PaymentFailedMailTest.php` (new)
- `tests/Integration/Mail/AccountAndPaymentMailRenderTest.php`
- `tests/Feature/General/DeadRoutesTest.php`

## Tests
- **`Integration/Payment/PaymentFailedMailTest` (new)** covers:
  - a declined card (a real `CardException`) is told once across three cron runs, and the invoice stays Unpaid;
  - a charge that did not complete is told;
  - no saved card is told once;
  - each invoice is told separately;
  - the bell uses `partner.payment.failed` and links to `/my-subscription`;
  - the email names the invoice and links to the billing page.
  These failed first, then passed.
- **Guards that passed before and after:**
  - a system error does not email the payer;
  - a card needing re-entry gets only the re-entry request.
- **`Feature/General/DeadRoutesTest::the_unsent_payment_notification_mail_is_gone`** failed first, then passed.
- **Existing tests still pass:** the `PaymentFailedMail` render test (with a transaction id), the card re-authorization tests and the auto-charge tests.
- **Suites run:** fch-api full suite: 2301 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- Payers now receive this email. For a payer who removed their default payment method (F110), that is one email per new invoice, telling them to pay it themselves.
- `__('payment.valued_customer')` and `__('payment.program_payment')` are reused from the existing mail code.
