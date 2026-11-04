# BUG-069: resuming a cancelled subscription, and reactivation on payment

**Date:** 2026-11-03

## Problem
QA's report (R2-CP-BILL-03) was that a client who cancels is locked out of almost every page on the next login, although the cancel dialog promises full access until the end of the billing period. That lockout is a portal bug, fixed in client-fch. Looking at the API side found three gaps:

- **No way back.** A cancelled subscription could not be reactivated. Neither the subscriber nor the host had an endpoint for it.
- **Silent reactivation.** Paying an invoice still open on a cancelled subscription set it back to Active (`handleInstallmentPayment()` and the others write `status = Active`). Nobody was told, and billing and auto-charge quietly started again.
- **No warning possible.** The portals could not tell that an invoice would reactivate a subscription, so they could not warn first.

## Solution
The client decided how this should work (2026-11-03). Resume keeps the current plan:

| Situation | Resume does |
|---|---|
| End date not passed | Active at once; any open invoice is charged by the cron as usual |
| End date passed, open invoice | Stays Cancelled; returns that invoice to pay |
| End date passed, nothing open | Raises the next bill (installment, or the partner's platform fee) and returns it to pay |
| Borrower who paid everything (`PHASE_COMPLETED`) | Active at once, no invoice |

**Endpoints and service:**
- **Resume service:** `SubscriptionResumeService::resume()` implements the table and answers:
  - `data.status`: `active` or `payment_required`;
  - `data.invoice_id`.
- **Borrower endpoint:** `POST /borrower/resume-subscription`.
- **Partner endpoint:** `POST /common/my-subscription/resume-subscription`. Only the partner's own login may call it, as for cancel.
- **Invoice for a resume:** `SubscriptionService::raiseResumeInvoice()` reuses the cron's `createInvoiceForSubscription()`, which now:
  - takes `$resuming` (allows Cancelled);
  - returns the invoice.
- **Reactivation on payment:** `handleSuccessfulPayment()` now wraps the old body (`applySuccessfulPayment()`). When a payment turns a Cancelled subscription Active, it sends the reactivation notice.
  - The next cycle runs from the payment day when the payment is late (existing `nextCycleFrom()`).

**Notice** (`PaymentMailService::sendSubscriptionReactivated()`):
- **Subscriber:** mail (`SubscriptionReactivatedMail`, `emails/subscription/reactivated`) and bell (`borrower.subscription.reactivated` / `partner.subscription.reactivated`).
- **Host:** bell (`host.subscription.reactivated`).
- **Client's partner:** bell (`partner.client_subscription.reactivated`); skipped when that partner is the host.

**Invoice flag:** the borrower and FCH invoice resources (list and details) now send `reactivates_subscription` (`Invoice::reactivatesSubscription()`): an open cycle invoice of a cancelled subscription. The portals use it to warn before payment.

## Files changed
- `app/Services/FCH/Subscription/SubscriptionResumeService.php`: new.
- `app/Services/FCH/Subscription/SubscriptionService.php`: `handleSuccessfulPayment()` wrapper, `raiseResumeInvoice()`, `createInvoiceForSubscription(..., $resuming)` returning `?Invoice`.
- `app/Services/GlobalPayment/PaymentMailService.php`: `sendSubscriptionReactivated()`.
- `app/Mail/Subscription/SubscriptionReactivatedMail.php` and `resources/views/emails/subscription/reactivated.blade.php`: new.
- `config/notifications.php`: the four `*.subscription.reactivated` types.
- `lang/en/subscription.php`: `resume.*`.
- `app/Models/Invoice/Invoice.php`: `reactivatesSubscription()`.
- `app/Http/Resources/{Borrower,FCH}/Invoice/Invoice{,Detail}Resource.php`: `reactivates_subscription`.
- `app/Services/Borrower/Invoice/InvoiceService.php`: eager-loads `subscription:id,status` for the list and unpaid queries.
- `app/Services/Borrower/Billing/BorrowerBillingService.php` and `app/Http/Controllers/Api/V1/Borrower/Billing/BillingController.php`: `resumeSubscription()`.
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php` and `app/Http/Controllers/Api/V1/FCH/Common/MySubscription/MySubscriptionController.php`: `resumeSubscription()`.
- `routes/borrower.php` and `routes/common.php`: the two routes.
- `database/seeders/E2EInstallmentBorrowerSeeder.php`: local-only E2E account on an installment plan, for client-fch's resume spec.

## Testing
- **New:** `tests/Feature/Subscription/ResumeSubscriptionTest.php` (19). Each failed first. It covers:
  - every row of the table, for borrower and partner;
  - not-cancelled refused;
  - the borrower acts only on their own subscription;
  - partner staff forbidden;
  - resuming twice raises one invoice;
  - paying an open invoice reactivates, notifies and moves the cycle;
  - paying an active subscription's invoice sends no notice;
  - the email renders;
  - the `reactivates_subscription` flag on borrower and partner, list and details.
- **Updated:** `tests/Integration/Invoice/InvoiceDetailResourceTest.php`, whose pinned key list gains `reactivates_subscription`.
- **Full suite:** 4529 tests passed, 2 skipped.
- **E2E (client-fch, live API):** the resume specs pass.

## Open items
- **Old unpaid invoices:** an invoice left unpaid when a subscription is cancelled stays Unpaid, and is not voided (the client's decision).
- **Host-side reactivation:** the host cannot reactivate from the admin Subscriptions screen. Only the subscriber can resume.
