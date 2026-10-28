# Auto-charge — never charge a paid invoice again, stop after 6 failures, settle paid-but-Unpaid invoices (BUG-005)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-005** (OP-INV-08, Critical, P1). Invoice INV-20260719001 (Kirsten Carter, one time enrollment, $2,497) has a **succeeded Stripe payment on 19 Jul** but stayed **Unpaid**.

The scheduler (`subscriptions:process-all`, 01:00 and 13:00) kept trying to charge it again: **29 failed $2,497 attempts** between 11 and 25 Oct, each one emailing the ops mailbox. Payments counted it as paid while Invoices showed $0. QA's retest on 25 Oct: still open.

- **Why Unpaid:** the payment was recorded by older code (July). Today's payment code (`UnifiedPaymentService`, `ChargeSubscriptionInvoiceJob`) records the payment and marks the invoice Paid in one transaction. That data was left behind on stage. The local database has no such invoice.
- **Why it kept charging:** the dispatcher took every Unpaid invoice on an Active subscription, and the job never checked for a succeeded payment and had no retry limit.
- **Double-charge risk:** the job's Stripe idempotency key (`invoice_{id}`) is honoured by Stripe for 24 hours only. A card that worked on a later pass would have paid $2,497 a second time.
- **Same pattern locally:** two invoices (INV-20260901001, INV-20260901002) with 15 failures each ("No saved Stripe Customer ID") were still being retried.

## Solution
1. **Never charge a paid invoice.** `ChargeSubscriptionInvoiceJob` checks `Invoice::isCoveredBySucceededPayments()` (succeeded payments ≥ the amount) before anything else. If covered, it does not call Stripe, records no failure, stops auto-charge with reason `already_paid`, and tells the ops mailbox **once** to reconcile it. The payer is not emailed.
2. **Stop after 6 failures.** After each failure, `markFailed()` counts the invoice's failed payments. At `config('subscription.auto_charge_max_failed_attempts')` (**6**, three days) auto-charge stops with reason `too_many_failures`, and **once**:
   - the payer gets `AutoChargeStoppedMail` and a bell notification (`borrower.payment.auto_charge_stopped` / `partner.payment.auto_charge_stopped`), with Pay Now to their billing page;
   - for a client, the **client's partner** gets the same email from the host, naming the client and linking to the invoice (`partner.client_auto_charge_stopped`).

   Failures from before count, so an invoice already past 6 stops on its next pass.

   The invoice stays Unpaid and payable by hand. No new invoice is raised meanwhile (one open invoice at a time, unchanged).
3. **The scheduler skips stopped invoices** (`whereNull('auto_charge_stopped_at')`), and the job returns early for one queued before it stopped.
4. **`php artisan invoices:reconcile-paid [--dry-run]`** finds every Unpaid invoice whose succeeded payments cover it and settles it the way a successful payment does: Paid on the day of the latest succeeded payment, then `SubscriptionService::handleSuccessfulPayment()` (activates the payer and moves the subscription on). `--dry-run` lists invoice, amount, paid on and subscription phase/status without changing anything. Each invoice is re-checked under a row lock. Safe to re-run.
5. **Stop columns:** `invoices.auto_charge_stopped_at` and `auto_charge_stop_reason` (`Invoice::AUTO_CHARGE_STOP_ALREADY_PAID`, `AUTO_CHARGE_STOP_TOO_MANY_FAILURES`). Null means still auto-charged.

## Files changed
- `database/migrations/2026_10_26_120000_add_auto_charge_stop_to_invoices_table.php`: new.
- `app/Models/Invoice/Invoice.php`: the stop columns (fillable, cast), reason constants, `isCoveredBySucceededPayments()`.
- `app/Jobs/ChargeSubscriptionInvoiceJob.php`: early returns (stopped / already paid), `stopAfterTooManyFailures()`, `stopAutoCharge()`, `notifyAdmins()` (moved out of `markFailed()`).
- `app/Console/Commands/ProcessAllSubscriptions.php`: skips stopped invoices.
- `app/Console/Commands/ReconcilePaidInvoices.php`: new command.
- `app/Services/GlobalPayment/PaymentMailService.php`: `sendAutoChargeStopped()`, which tells the payer and the client's partner.
- `app/Mail/Payment/AutoChargeStoppedMail.php` and `resources/views/emails/payment/auto_charge_stopped.blade.php`: new (the shared mobile-responsive layout).
- `config/subscription.php`: `auto_charge_max_failed_attempts` = 6.
- `config/notifications.php`: three notification types.
- `lang/en/payment.php`: `auto_charge_stopped.subject`, `auto_charge_stopped.already_paid_alert`.
- **Tests:**
  - `tests/Integration/Payment/AutoChargeStopTest.php`: new, 9 tests.
  - `tests/Feature/Invoice/ReconcilePaidInvoicesCommandTest.php`: new, 4 tests.
  - `tests/Integration/Payment/StripeChargeIdempotencyTest.php`: the second pass now also removes the first pass's payment row, so "succeeded but not recorded" is what it tests. A recorded payment now stops the job before Stripe is called.

## Tests
- **`AutoChargeStopTest`** (8 failed first):
  - a covered invoice never reaches Stripe, records no failure and is stopped as `already_paid`;
  - ops told once, payer not told;
  - a partial payment does not stop it;
  - stops on the 6th failure, not the 5th, with nothing attempted after;
  - 29 earlier failures stop it on the next pass;
  - a partner payer is told once;
  - a client **and** the client's partner are each told once;
  - the email names the invoice and says Pay Now;
  - the scheduler does not queue a stopped invoice.
- **`ReconcilePaidInvoicesCommandTest`** (all failed first):
  - a dry run lists and changes nothing;
  - a real run marks it Paid on 2026-07-19 and completes the client's one time enrollment;
  - partial or failed-only payments are untouched;
  - a second run finds nothing.
- `tests/Integration/Payment` + `tests/Feature/Billing` + `tests/Feature/Payment`: 276 passed.
- **Full suite:** 3,755 tests, 1 failure, 2 skipped. The failure is not from this change: `NavigationMapTest` › `borrower_support:capital_access` (client-fch moved Capital Access to `/credit-card-stacking`).
- **Local:** migration run; `invoices:reconcile-paid --dry-run` finds none.

## Open items — on stage
1. `php artisan migrate`. The scheduler reads the new column, so deploy and migrate together.
2. `php artisan invoices:reconcile-paid --dry-run`: check INV-20260719001 is listed (amount 2,497.00, paid on 2026-07-19) and that nothing unexpected is.
3. `php artisan invoices:reconcile-paid`.
4. Until step 3 runs, the first scheduler pass after deploy stops auto-charge on INV-20260719001 (`already_paid`) and emails the ops mailbox once.

- The 29 failed payment rows stay as history.
- The stop is not undone when a payer saves a new card (as decided). They pay the stopped invoice by hand; the next invoice is auto-charged as usual.
- QA sheet: BUG-005 → Fixed after the stage retest.
