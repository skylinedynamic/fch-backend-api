# Invoice PDF: billed to whoever pays, named by its type (BUG-137)

2026-10-29

## Problem
A partner's Borrower Fee invoice PDF was addressed to the borrower and labelled as the borrower's enrollment, while the invoice window bills the partner.

Example: Wanda onboards a paid client. The $800 Borrower Fee invoice INV-20261028001 is created for Wanda and paid with Wanda's card.

| | Invoice window | Downloaded PDF |
|---|---|---|
| Billed To | Wanda Romanoff (Dark Magic) | the client ("QA-TEST Retest033 Paid"), with the client's phone and email |
| Line item | "Borrower Fee" | "ENROLLMENT DETAILS - One Time Payment" |
| Card Holder | Wanda Romanoff | the client |

**Cause.** The window reads whoever pays: `entity_type`/`entity_id`, through `InvoicePartyResolver::billedTo()`. `resources/views/invoices/download.blade.php` had its own rule instead: `invoices.borrower` first, then the partner. A Borrower Fee invoice carries the client in `borrower_id`, so the PDF named the client. Its item column was always "Enrollment Details", and the line item defaulted to the subscription's enrollment type or "Platform Service Enrollment", whatever the invoice type.

The same rule also caused two other faults:
- **Commission payout PDFs** also carry `borrower_id`, so "PARTNER DETAILS" showed the client, not the partner being paid.
- **The borrower address never printed.** The template read `business_address_line_1`, which is not a column on `borrowers`.

## Solution
The PDF now uses the same rule as the window.
- **Billing Address / Partner Details and Card Holder / Payee Name** come from `InvoicePartyResolver::billedTo($invoice)`, which is whoever pays (the payee on a payout). The block shows:
  - name and company;
  - address (street, city, state and postal code);
  - phone and email.
- **Item column** is "Description", and the line item is the invoice's type ("Borrower Fee", "Down Payment", "User Seat" and so on), as `InvoiceResource::getFormattedType()` shows it. The plan name sits under it only on an invoice that bills for the plan (`Invoice::billsSubscriptionPlan()`). Explicit `details.items` are still printed as they are.

The fix is in the view, so all three users of it change together:
- the admin download (`FCH\Common\Invoice\InvoiceService::downloadInvoice`)
- the invoice email (`sendInvoice`)
- the borrower download (`Borrower\Invoice\InvoiceService::downloadInvoice`)

## Files changed
- `resources/views/invoices/download.blade.php`: the payer from `InvoicePartyResolver`, the "Description" column and a type-based line item.
- `tests/Feature/Invoice/InvoicePdfBilledToTest.php` (new).

## Testing
- `tests/Feature/Invoice/InvoicePdfBilledToTest.php` (5 tests):
  - a Borrower Fee PDF is addressed to the partner who pays (name, address, email), with no client details;
  - the Card Holder is the partner;
  - it reads "Borrower Fee", with no "Enrollment";
  - an invoice the borrower pays is still addressed to the borrower and reads "Down Payment";
  - a commission payout's Partner Details and Payee Name are the partner.

  The first four were red before the fix. The fourth failed on "Down Payment": the PDF printed "Platform Service Enrollment". The payout test was written after the fix.
- `tests/Feature/Invoice` and `tests/Integration/Invoice`: 42 passed, including BUG-093's `InvoicePdfPaymentDetailsTest`.
- Full suite: see below.

## Open items
- The fixed NOTES text on every non-payout invoice ("...the credits details supplied as confirmation of work undertaken will be charged the agreed quoted fee...") reads like leftover template text. Its wording is a product decision, so it is unchanged.
- Invoices already downloaded or emailed keep the old PDF. A new download prints the right details.
