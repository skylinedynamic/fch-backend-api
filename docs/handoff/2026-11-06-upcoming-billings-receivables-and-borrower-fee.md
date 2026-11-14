# Upcoming Billings: who is owed what, borrower fee on the host's list

**Date:** 2026-11-06

## Problem
Upcoming Billings (`/billing`, `POST /common/billing/fetch-upcoming`) shows the money owed **to** the viewer. The borrower fee was on the wrong side:
- The $800 fee a partner pays the host to activate a client is raised on the **client's subscription**. While it was unpaid, it was added to that client's Outstanding on the **partner's** list, as if the client owed the partner.
  - Local simulation with partner 16, client 67, INV-20260524003: Outstanding was $1,300, which is the $500 installment plus the $800 fee.
- A partner's client whose only open invoice was the fee was listed on the partner's list.
- The **host**, who is owed the fee, never saw it.
- "Owed" counted only Unpaid invoices. A Processing invoice (manual payment awaiting approval) was not counted.

Agreed rules (2026-11-06):
- **Partner**: only its own clients' bills, i.e. enrollment and installment. That means due within the window (`subscription.upcoming_window_days`, 10) plus every open bill.
- **Host**: its own clients' bills, plus the partners' plan (enrollment, installment, platform fee), staff seats and borrower fees. Due within the window plus every open bill.
- **Open** = Unpaid or Processing (`Invoice::OPEN_STATUSES`).
- **An unpaid borrower fee** is the partner's row on the host's list, naming the client it is for.

## Solution
- `Invoice::OPEN_STATUSES` and `Subscription::openInvoices()`.
- `BillingService::fetchUpcoming()`:
  - **Regular rows:** `whereBilledToViewer()` + `whereCollecting()` + `whereDueWithinWindow()`. On a partner's list, only invoices with `is_partner_paying = false` count as owed.
  - **Host-only borrower fee rows:** a non-host partner's client subscription with an open borrower fee (`type = borrower_fee`, `is_partner_paying`). The query selects `billing_row` = `borrower_fee` | `subscription` (`BillingResource::ROW_*`).
  - **`receivableFor()`:** which open invoices count on each row. Used for `outstanding_amount` (as the `receivableInvoices` relation) and for the summary.
  - **A borrower fee row in each control:**
    - Entity filter: counts as **Partner**, never Borrower.
    - Views: **Overdue** while the partner is Active, **Paused** while the partner is not, never **Scheduled**.
    - Billing Type filter: "Borrower Fee".
    - Sorts:
      - Date sorts on the day the fee was raised.
      - Amount and Outstanding sort on the fee amount.
      - Type sorts on `borrower_fee`.
      - Name sorts on the partner's name. This uses the new optional `$shownAs` on `Subscription::scopeOrderBySubscriberName()`.
  - **Summary:** fee rows count in outstanding, then in overdue or paused, never in scheduled.
- `BillingResource`, borrower fee row:
  - Subscriber: the partner. Adds `for_client_name`, `fee_invoice_id` and `fee_invoice_number`.
  - Amount and Outstanding: the fee.
  - Date: when the fee was raised.
  - Type: `billing.type.borrower_fee`.
  - Status: the fee invoice's.
  - Paused only by the partner's status (`borrowerFeePausedReason()`).
- Admin panel: `Fch-admin-frontend-react/docs/handoff/2026-11-06-upcoming-billings-borrower-fee-row.md`.

## Files changed
- `app/Models/Invoice/Invoice.php`: `OPEN_STATUSES`.
- `app/Models/Subscription/Subscription.php`: `openInvoices()`; `scopeOrderBySubscriberName()` takes an optional `$shownAs`.
- `app/Services/FCH/Common/Billing/BillingService.php`: scoping, window, filters, views, sorts, summary and helpers.
- `app/Http/Resources/FCH/Billing/BillingResource.php`: `ROW_*`, `borrowerFeePausedReason()`, the fee row, and outstanding from `receivableInvoices`.
- `lang/en/billing.php`: `type.borrower_fee`, `partner_not_active`.
- `tests/Feature/Billing/UpcomingBillingReceivablesTest.php` (new, 5 tests).
- `database/seeders/E2EUnpaidBorrowerFeeSeeder.php` (new, local only): an E2E client of the first Active non-host partner with an Unpaid $800 borrower fee. Idempotent.

## Testing
- `UpcomingBillingReceivablesTest`: 5 tests, all red first (2 errors, 3 failures), then green. They cover:
  - the partner's list without the fee
  - the host's list with clients, partner plan, platform fee, seat and fee row
  - Processing counted as owed
  - fee row filters, views and sorts
  - fee paused by the partner's status
- `tests/Feature/Billing` + `PartnerAccountApiTest` + `tests/Feature/Subscription`: 153 passed.
- Full fch-api suite: 4958 tests passed, 2 skipped, 0 failures.
- Local MySQL with the real data, through `BillingService` as host and as partners 6 and 16:
  - **Unchanged data:** the same rows and totals as before (host 11, #16 1, #6 3).
  - **With INV-20260524003 set Unpaid inside a rolled-back transaction:**
    - The host gets the fee row "Awlad Hossain (for Awlad Hossain), Borrower Fee 800, Unpaid", and outstanding rises by $800.
    - Partner 16's outstanding stays $500.
    - Every sort, view, entity filter and the type filter runs on MySQL.
- Admin live E2E `e2e/sidebar/upcoming-billings/` (borrower fee + existing): 5/5 passed.

## Open items
- **Old local data:** one borrower fee with no subscription (INV-20260418002, partner 6, Paid) cannot appear on this list. All new fees are raised on the client's subscription.
- **Amount and Date column filters:** these still match subscription columns, so they do not narrow borrower fee rows.
- **Status sort:** still sorts by the subscription status, not the fee invoice status.
- **Old installments:** an Expired installment is not counted as owed (it is neither Unpaid nor Processing).
- **Local test data:** the E2E seeder leaves client `e2e.borrower-fee@example.test` with an Unpaid fee in the local DB.
- Not committed.
