# Invoices name the fee "Client Activation Fee" (BUG-194)

**Date:** 2026-11-10
**Branch:** bugfix/qa-round1-run2
**Author:** arafat-invento

## Problem
QA Round 1 Run 2, BUG-194 (OP-PF-01, Owner / Partner, Invoices): the partner's
$800 fee per client was renamed "Client Activation Fee" on Plan & Fee (QA
decision D3), but every invoice still called it "Borrower Fee":
- the Type column and invoice window in the admin and borrower portals
- the invoice PDF's line description
- the host's "new fee invoice" email (subject and amount heading)

The label was the stored type (`borrower_fee`) with its underscores turned into
spaces, built separately in three API resources and the PDF view.

The sheet's latest retest column said "not a bug", but the bug's Comments
record the name as confirmed. Arafat decided on 2026-11-10 to apply the
rename (findings F254).

## Solution
- `Invoice::typeLabel(?string)` and `$invoice->typeName()` are now the single
  place an invoice type gets its name. `INVOICE_TYPE_LABELS` maps
  `borrower_fee` to "Client Activation Fee"; every other type is shown in words
  as before ("User Seat", "Down Payment"), and an untyped (older) invoice gives
  an empty string.
- The FCH and Borrower invoice resources, the PDF view and the email use it.
- **The stored type is unchanged** (`borrower_fee`), so no data migration, no
  change to filters, and no change for the frontends, which display the
  `type` string the API sends. Only the name a person reads changes.

## Files changed
- `app/Models/Invoice/Invoice.php`: `INVOICE_TYPE_LABELS`, `typeLabel()`, `typeName()`
- `app/Http/Resources/FCH/Invoice/InvoiceResource.php` (also used by its `InvoiceDetailResource`): type from `typeName()`
- `app/Http/Resources/Borrower/Invoice/InvoiceResource.php`, `InvoiceDetailResource.php`: same
- `resources/views/invoices/download.blade.php`: PDF line description from `typeName()`
- `app/Mail/Partner/BorrowerFeeInvoiceNotificationMail.php`: subject "New Client Activation Fee Invoice #..."
- `resources/views/emails/partner/borrower_fee_invoice.blade.php`: "Client Activation Fee" in the intro and amount heading
- `docs/test-findings.md`: F254, O85

## Tests
- Added: `tests/Unit/Models/InvoiceTypeLabelTest.php` (every type's name; `borrower_fee` is "Client Activation Fee" while the stored type stays `borrower_fee`)
- Added: `InvoiceListTest::a_borrower_fee_row_is_typed_as_a_client_activation_fee` (Integration, admin list)
- Added: `RecordMailRenderTest::the_new_fee_invoice_email_names_it_a_client_activation_fee` (Integration, subject + body)
- Updated: `InvoiceDetailResourceTest::a_borrower_fee_carries_its_own_amount_and_none_of_the_borrowers_plan`, which now expects "Client Activation Fee"
- Updated: `InvoicePdfBilledToTest::a_borrower_fee_is_named_a_client_activation_fee_not_an_enrollment` (Feature; renamed, expects "Client Activation Fee $800.00" and no "Borrower Fee")
- Written first and seen to fail (methods missing, old label), then fixed.
- Suites run: the invoice/mail tests (83 passed); full suite: see the session report.

## Open / follow-up
- **O85**: the admin Subscription Details page still heads the subscription's
  `borrower_fee_amount` "Borrower Fee". That's a subscription page, not an
  invoice, so it was left as it is.
- Internal names (`borrower_fee` type, `BorrowerFeeInvoiceNotificationMail`,
  the `borrower_fee_invoice` view) are unchanged on purpose; renaming them
  would change data and API values.
- The QA sheet's BUG-194 row can move to Fixed.
