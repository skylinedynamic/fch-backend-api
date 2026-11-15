# BUG-202 follow-ups: save card only when asked, and who paid / approved / rejected

**Date:** 2026-11-11
**Branch:** round-1-run-2-fix
**Author:** Awlad Hossain

## Problem
Two follow-ups from BUG-202 (partner staff can pay the partner's invoices):

1. **A card was kept without asking.**
   - On the local record: the first card that paid an invoice became the payer's card on file when the payer had none. For a partner, that could be a staff member's own card.
   - On Stripe: **every** card payment made that card the customer's default (`setDefaultPaymentMethod`), even when a card was already on file. That default is the card the subscription auto-charge uses, and it changed without the local record showing it.
2. **No names shown.** `paid_by`, `approved_by` and `rejected_by` were stored but appeared in no API response. Payment Details showed Approved/Rejected By only from the name written into `details` at the time.

## Solution
**Save card (decided 11-Nov: a "Save card as default payment method" checkbox in every payment modal; the card is kept only when ticked).**
- `ProcessPaymentRequest`: new optional `save_card` (boolean).
- `UnifiedPaymentService::processPayment()` puts it on the checkout and passes it to the driver.
- **`StripePaymentDriver::charge()`:**
  - Ticked: the card is attached and made the customer's Stripe default, and it replaces the payer's card on file (`default_payment_method`, `stripe_customer_id`).
  - Not ticked: the card only pays. Nothing is attached and the record is left alone. No duplicate customer is created, because `resolveCustomer()` finds the existing one by email.
- **`completeCharge()`:** writes the card details for Stripe only when `save_card` was ticked. Manual payments are unchanged.
- **3-D Secure:** the driver has already saved, or not saved, the card before the challenge. The settle step writes nothing.

**Names (decided 11-Nov: show them on Payment Details and Invoice Details).**
- **Relations:**
  - `Payment::paidBy()`, `approvedBy()`, `rejectedBy()`
  - `Invoice::paidBy()`
- **`FCH\Common\Payment\PaymentResource`:**
  - adds `paid_by_name`, `approved_by_name`, `rejected_by_name`, each the user's current name
  - Approved and Rejected fall back to the name in `details`, for payments from before the ids were stored
  - only included when the relations are loaded, so the Payments list (lazy loading is off) is unaffected
- **`FCH\Invoice\InvoiceDetailResource`:** `paid_by_name`, also only when loaded.
- **`PaymentService::find()` and `InvoiceService::find()`** (the details endpoints) eager-load these users.

**Also:** `StripePaymentDriver` uses `Payment::CURRENCY_USD` instead of a hard-coded `'usd'`, lower-cased for Stripe as before.

## Files changed
- `app/Http/Requests/Api/V1/GlobalPayment/ProcessPaymentRequest.php`: `save_card`.
- `app/Services/GlobalPayment/UnifiedPaymentService.php`: `save_card` on the checkout; the card is written only when ticked.
- `app/Services/GlobalPayment/Drivers/StripePaymentDriver.php`: attach, default and record write only when ticked; `Payment::CURRENCY_USD`.
- `app/Models/Payment/Payment.php`, `app/Models/Invoice/Invoice.php`: the user relations.
- `app/Http/Resources/FCH/Common/Payment/PaymentResource.php`, `app/Http/Resources/FCH/Invoice/InvoiceDetailResource.php`: the names.
- `app/Services/FCH/Common/Payment/PaymentService.php`, `app/Services/FCH/Common/Invoice/InvoiceService.php`: eager-load on find.
- `tests/Feature/Payment/SaveCardOnPaymentTest.php` (new, 6):
  - unticked: nothing saved, and the existing card is kept
  - ticked: the card is saved, or replaces the existing one
  - 3-D Secure unticked: nothing saved
  - `save_card` must be boolean
- `tests/Feature/Payment/PayerAndReviewerNamesTest.php` (new, 6):
  - paid by and approved by
  - rejected by
  - the current name is shown
  - the fallback to `details`
  - the invoice's Paid By, and none when nobody paid
- `tests/Integration/Payment/StripeChargeUsesConfiguredModeTest.php`: the charge helper passes `save_card => true`, because these tests are about the card kept on file.

## Tests
- **New tests:** failed first, then passed.
- **First broad run:** 6 failures from the names, all fixed.
  - 4 were the Payments list and commission entries (lazy-loading violation). Fixed by including the names only when loaded.
  - 2 were the invoice payload key list.
- **Final runs:**
  - `tests/Feature/Payment` + `tests/Integration/Payment`: 311 passed.
  - Broad run: see the session summary.
- **Local DB:** migration `2026_11_07_000001` (BUG-202) was still Pending, and payments would have failed on the missing columns. It has now been run. `PayInvoicePermissionSeeder` has **not** been run locally ("Pay Invoice" is missing there).

## Open / follow-up
- **Deploy with both frontends.** They send `save_card`. An old frontend sends nothing, so no card is saved.
- **Payers whose card was set silently before this change keep it.** Nothing is undone.
- **Payers who never tick the box get no card on file,** so the auto-charge cannot charge them. That follows from the decision.
- **Local data:** partner 1's Stripe is in production mode, which breaks the borrower live payment E2E (borrower-fch `docs/e2e/ISSUES.md` E2E-029).
- Not committed.
