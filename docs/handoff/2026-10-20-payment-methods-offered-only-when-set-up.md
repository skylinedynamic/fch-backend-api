# Payment methods are offered only when set up; Stripe keys are checked on save

**Date:** 2026-10-20
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
- **Stripe switched on without usable keys.** `GET /api/v1/payment/get-payment-methods` still listed it, with `publishable_key: null`. The payment modal in both portals then showed "Initializing secure payment gateway..." forever, because `getStripe(null)` never resolves.
- **Bank Transfer without a bank account** was offered too, and payment then failed with `payment.bank_account_required`.
- **Wrong Stripe keys.** Keys that looked right but were wrong (a typo, or keys from two different accounts) were saved without complaint. The payer only found out at Pay, from Stripe's raw "Invalid API Key provided" or "No such PaymentMethod".

## Solution
- **One rule for "can take a payment".** `App\Support\Payment\PaymentSetup::problems()` checks the linkage's active mode:
  - **Stripe** (`App\Support\Payment\StripeKeys`): the publishable key must be `pk_(test|live)_…`, the secret key must be `(sk|rk)_(test|live)_…`, and both must be on the same side (both test, or both live).
  - The mode a key is saved under is **not** compared. Test keys under Production work (staging), so they are still offered. Mode mismatches on save are refused by the existing `StripeKeysMatchPaymentMode` rule.
  - **Bank Transfer:** needs at least one active bank account for the mode. This uses the new `PaymentMethodBankDetail::availableFor()` scope, the same query the listing already used.
  - **Everything else** (cash and the manually reconciled gateways) needs nothing.
- **Gateways paid by reference number** (Square, Authorize.Net, Ecrypt and any other credential-based provider): `PaymentSetup::issues()` reports each credential left empty for the active mode, for example "The Sandbox Application ID is missing." It also reads the flat, mode-suffixed names saved before the per-mode split, as the settings form does. These gateways **stay offered to payers**, because payment is manual and hiding them would break payments that work today. So they are reported to the partner, not filtered.
- **The payer list.** `PaymentMethodService::getActiveProvidersForPartner()` leaves out any method with problems. This covers both portals' invoice payment modals and the partner portal's My Subscription.
- **The settings endpoints.** `setup_issues` (everything missing, empty when set up) and `can_take_payments` (whether payers are offered the method meanwhile) are added to:
  - the list (`PartnerPaymentProviderListResource`), where the partner portal marks the row "Not configured";
  - a method's details, its save response and its status-toggle response (`PartnerPaymentProviderResource`), where the partner portal shows the warning on the method's own page. The unsaved placeholder of a method never set up has none.
  - Existing fields, including `is_configured`, are unchanged.
- **Checking keys on save.** `StorePartnerPaymentProviderRequest::after()` calls `App\Support\Payment\StripeKeyVerifier` once the rules pass. The verifier asks Stripe three read-only questions:
  1. `GET /v1/account` with the secret key;
  2. `POST /v1/tokens` with the publishable key (a throwaway PII token with dummy data);
  3. `GET /v1/tokens/{id}` with the secret key, to confirm both keys are in the same account.
- **When the save is refused.** Only on a definite "no": 401 for a key, or 404 `resource_missing` for the token. The error goes on `configuration.<mode>.publishable_key` or `.secret_key`.
- **When the save goes through anyway.** Stripe unreachable, a 5xx, a 429, or a 403 for a restricted key all let the save through, with a log warning. A Stripe outage never becomes a settings outage.
- **What gets checked.** Only modes whose keys changed against what's saved, so re-saving the form for something else asks Stripe nothing. A plainly malformed key is refused without calling Stripe. Keys are never logged.

## Files changed
- `app/Support/Payment/PaymentSetup.php` (new): the readiness rule and mode labels.
- `app/Support/Payment/StripeKeys.php` (new): Stripe key shape and pairing.
- `app/Support/Payment/StripeKeyVerifier.php` (new): the save-time Stripe check.
- `app/Models/PaymentMethod/PaymentMethodBankDetail.php`: `scopeAvailableFor()`.
- `app/Services/FCH/Common/PaymentMethod/PaymentMethodService.php`: filters unready methods and uses the scope.
- `app/Http/Resources/FCH/PaymentMethod/PartnerPaymentProviderListResource.php`: `setup_issues`.
- `app/Http/Resources/FCH/PaymentMethod/PartnerPaymentProviderResource.php`: `setup_issues` on details, save and status responses.
- `app/Http/Requests/Api/V1/FCH/Common/Setting/StorePartnerPaymentProviderRequest.php`: the `after()` Stripe check.
- `lang/en/payment_method.php`: mode labels, setup messages (including `setup_credential` and the `credential.*` field names), Stripe rejection messages.

## Tests
- Added `tests/Feature/Payment/PaymentMethodSetupTest.php` (42): everything below, plus details/save/status `setup_issues`, `can_take_payments`, and the Square / Authorize.Net / Ecrypt credential checks (none, one missing, all present, legacy flat names, active mode only):
  - working key shapes, including staging test keys under Production, live keys under Sandbox, and restricted keys;
  - each unusable shape;
  - only the active mode counts, and legacy flat config still counts;
  - Bank Transfer with and without an account, the other mode, a switched-off account;
  - Cash;
  - `setup_issues` on the settings list.
- Added `tests/Feature/Payment/StripeKeyVerificationTest.php` (15):
  - accepted keys, and each key sent only to its own endpoint;
  - a rejected secret key, a rejected publishable key, keys from two accounts, a malformed key (no Stripe call);
  - unreachable, 5xx/429 and restricted-key 403 don't block;
  - every changed mode is checked, empty modes and unchanged keys are skipped;
  - the existing mode rule runs first, and other providers are never checked.
- Updated `tests/Feature/Payment/PaymentSettingsEndpointsTest.php`: stubs Stripe and adds `Http::preventStrayRequests()`, because its made-up keys would otherwise reach the real Stripe.
- `php artisan test`: 3263 passed, 2 skipped.

## Open / follow-up
- **Before deploying**, check stage and production for active methods this will hide. Settings → Payment Integration shows them per partner. Locally 11 active Stripe linkages are hidden, and all are unusable (missing keys, junk text, `Pk_test_`, `mk_…` as the secret).
- **E2E data:** the borrower Stripe E2E (`borrower-fch/e2e/sidebar/invoices/invoice-payment.spec.ts`) fails locally because the host partner's Stripe, Cash and Bank Transfer are all switched off (`status=0`). The old code returned an empty list for that data too. The host's sandbox keys pass the new rule, so the spec works again once Stripe is switched on.
- `PaymentProvider::isOfferedBy()` (choosing a default payment method in billing or My Subscription) still only checks that a method is switched on. It wasn't changed, to keep this change small.
- If Stripe.js itself fails to load (ad-blocker, network), the card form can still wait forever. That needs a `.catch` in the portals' `StripeForm`, which isn't done.
