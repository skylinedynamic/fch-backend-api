# Remove the default payment method (F110)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F110

## Problem
A partner (My Subscription) or a borrower (Billing) could change their default payment method but could never take it off. A saved card stayed the default and was always auto-charged.

There was also a display problem: both billing views fell back to "Stripe" when no default was recorded, so "no default" could not be shown at all.

## Solution
**Decisions (user):**
- Removing is allowed while the subscription is active, and the UI warns first.
- Nothing is deleted. Payments stay, and the Stripe customer and cards stay. The method only stops being the default.
- A later manual card payment saves that card as the default again, as it always has.
- Borrowers get the same option.

**New endpoints**
- `POST /common/my-subscription/remove-default-payment-method` (partner owner only, see F109)
- `POST /borrower/remove-default-payment-method`

**What a remove does**
- Sets `default_payment_method` to null and clears `stripe_customer_id` (the saved Stripe customer reference with the card name and last 4).
- `ChargeSubscriptionInvoiceJob` already refuses to charge without a saved customer ("No saved Stripe Customer ID"). So nothing is auto-charged until a default is set again. Invoices stay Unpaid and can be paid manually.
- Clearing the customer reference is required. `StripePaymentDriver` charges the customer's default card **or any card on the customer**, so keeping the reference would still charge a card.
- Nothing is lost at Stripe. `StripeCustomerService::resolveCustomer()` finds the same customer again by email on the next card payment.

**What the billing views show**
- My Subscription and borrower Billing now show **no gateway** (`default_payment_method: null`, `payment_provider: null`) when none is set.
- A card saved before defaults were recorded (null default with a saved customer) still shows as Stripe, as before.

**Portals**
- react-fch My Subscription and borrower-fch Billing get a "Remove" button and a warning modal.
- After a remove, the method card reads "No default payment method".
- borrower-fch's Upcoming card says it is not charged automatically.
- See `react-fch/docs/handoff/2026-10-13-remove-default-payment-method.md` and `borrower-fch/docs/handoff/2026-10-13-remove-default-payment-method.md`.

## Files changed
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`: `removeDefaultPaymentMethod()`, `shownDefaultProvider()`
- `app/Services/Borrower/Billing/BorrowerBillingService.php`: same two methods
- `app/Http/Controllers/Api/V1/FCH/Common/MySubscription/MySubscriptionController.php`
- `app/Http/Controllers/Api/V1/Borrower/Billing/BillingController.php`
- `routes/common.php`, `routes/borrower.php`
- `lang/en/partner.php`, `lang/en/borrower.php` (`default_payment_method_removed`)

## Tests
- Written to fail first, then passing:
  - `Feature/Partner/PartnerAccountApiTest::a_partner_removes_its_default_payment_method`: default and customer cleared, payments kept, the view shows none.
  - `Feature/Billing/BorrowerBillingApiTest::a_borrower_removes_their_default_payment_method`: the same, and another borrower is untouched.
- Characterisation tests, passing first time:
  - `a_saved_card_with_no_recorded_default_still_shows_as_stripe`, partner and borrower;
  - `Integration/Payment/SubscriptionAutoChargeModeTest::a_payer_who_removed_their_default_is_not_charged`: Stripe is not contacted and the invoice stays Unpaid. This pins the existing job behaviour this feature relies on.
- Suites run: fch-api full suite: 2282 passed, 42 skipped, 0 failed; real `storage/app` unchanged.

## Open / follow-up
- Deploy fch-api, react-fch and borrower-fch together. The Remove buttons call the new endpoints.
- Cancellation and reactivation are deferred (**O56**).
