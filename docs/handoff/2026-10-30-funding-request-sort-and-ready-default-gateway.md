# Funding Request list sort; default payment method must be ready (BUG-150, BUG-151)

**Date:** 2026-10-30
**Branch:** bugfix/all-bugfix
**Author:** Awlad

## Problem
- **BUG-150** (admin/partner portal > Intake & Review > Funding Request, `POST common/funding-request`):
  - **Borrower Name** sorted on `first_name`, then `last_name`. With first "QA-TEST" + last "R2 Wanda Embed Two" and first "QA-TEST ONB" + last "Wanda x11", the R2 row came first, though the column reads "QA-TEST ONB…" before "QA-TEST R2…".
  - **Application Status** was not in `$allowedSortFields`, so it fell back to newest first. The arrow toggled but the rows never moved.
- **BUG-151:** `POST borrower/update-default-payment-method` accepted any gateway the partner had switched on. Stripe with no keys could become the default (and with no card, the Stripe branch then failed with a 500). Pay Now (`/payment/get-payment-methods`) already offered only gateways that pass `PaymentSetup::isReady()`.

## Solution
- **BUG-150** (`FundingRequestService::list`):
  - Borrower Name sorts on `CONCAT_WS(' ', first_name, last_name)`, the name as the column shows it.
  - `application_status` is now sortable, in lifecycle order of the latest application, with "no application yet" first. This is the same expression as the borrower's own list (BUG-009).
- **BUG-151:** `PaymentProvider::isOfferedBy()` now also requires the partner's linkage to pass `PaymentSetup::isReady()`. It's used by both "make default" paths: the borrower's billing and the partner's own subscription. So neither can pick a gateway that can't take payments.
- Invoice payment has no host-key fallback: `resolveStripeSecretKey()` uses the receiving partner's keys or throws. So "not set up = not offered" is the right rule for both dialogs.

## Files changed
- `app/Services/FCH/Common/FundingRequest/FundingRequestService.php`: name sort on the full name; `application_status` sort.
- `app/Models/PaymentMethod/PaymentProvider.php`: `isOfferedBy()` checks readiness.

## Tests
- Added in `tests/Feature/FundingRequest/FundingRequestListSortSearchTest.php`:
  - `borrower_name_sorts_on_the_name_as_the_column_shows_it`
  - `application_status_sorts_both_ways_by_the_application_lifecycle`
  - Both seen failing first.
- Added in `tests/Feature/Billing/BorrowerBillingApiTest.php`: `a_gateway_switched_on_but_not_set_up_cannot_be_the_default`. It returned 500 before; now 422 on `provider_name`.
- Updated: `tests/Integration/Payment/BorrowerCardSaveTest.php` › `choosing_another_provider_clears_the_stripe_record`. It made Bank Transfer the default with no bank account, which now isn't ready (just as Pay Now never offered it). The fixture now gives the partner a production bank account, and the assertions are unchanged.
- Suites run: Feature/FundingRequest 61/61, Feature/Billing 74/74.
- Full suite: 4079 tests, with 1 failure (the fixture above). After the fixture fix, Integration/Payment, Feature/Billing, Feature/Payment and Feature/Subscription: 303/303 pass.

## Open / follow-up
- The client-fch Billing dialog now lists only `can_take_payments` gateways (client-fch handoff of the same date). The admin partner's own My Subscription dialog already lists from `/payment/get-payment-methods`, so it already showed only ready gateways.
- The admin column needs no change: it already sends `sort_field=application_status`.
