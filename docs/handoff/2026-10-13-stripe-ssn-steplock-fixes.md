# Stripe key swap guard, SSN overwrite, capital-access step schedule lock

**Date:** 2026-10-13 · **Branch:** `e2e-missing-tests` (uncommitted; the branch already had unrelated EmbedForm/Appointment changes) · **Found by:** borrower-portal E2E submit flows (`fch-borrower-react/docs/e2e/ISSUES.md` E2E-014, E2E-015, E2E-016)

## Problem
1. **E2E-014 (security):** partner 1's sandbox Stripe block had `publishable_key = sk_test_…` and `secret_key = pk_test_…`. `StripeKeysMatchPaymentMode` only checked test vs live, so the save passed. `PaymentCredentials::publishableKey()` then handed a **secret key to the browser**, and every sandbox card payment failed.
2. **E2E-015 (PII / data loss):** the borrower's Personal Info save sends `last_4_ssn`. The legacy `Borrower::setLast4SsnAttribute()` encrypted those 4 digits straight into `ssn_encrypted`, replacing the full 9-digit SSN on every save, even when nothing had changed.
3. **E2E-016:** the roadmap locks a step by schedule (a "Day N" delay after the play starts, and a time-of-day window), but `validateStepAccess()` did not check it. A direct `POST …/step/{seq}/submit` for a locked step returned 200.

## Solution
1. `StripeKeysMatchPaymentMode` also rejects a `sk_`/`rk_` key in `publishable_key` and a `pk_` key in `secret_key` (new lang key `payment_method.stripe_key_field_mismatch`). `PaymentCredentials::publishableKey()` never returns a `sk_`/`rk_` key.
2. `setLast4SsnAttribute()` is a no-op when the incoming digits match the stored SSN's last 4. A different last 4, or a blank, behaves as before.
3. New `App\Services\Borrower\CapitalAccess\StepTimeLock` is a pure port of the portal's `isTimeActive()`. `validateStepAccess()` calls it, through `stepTimeLockReason()`, for steps without an application. It uses the play start (play row, else the earliest application), the time-setting windows and the caller's `X-Timezone`, and answers 403 with the reason.
4. Two local-only seeders for the portal E2E suite (refuse to run outside local/testing):
   - `E2EUnpaidInvoiceSeeder`: idempotent $1 `borrower_fee` invoice.
   - `E2EResetBorrowerPasswordSeeder`.

## Files changed
- `app/Rules/StripeKeysMatchPaymentMode.php`, `app/Support/Payment/PaymentCredentials.php`, `lang/en/payment_method.php`
- `app/Models/Borrower/Borrower.php` (`setLast4SsnAttribute`)
- `app/Services/Borrower/CapitalAccess/StepTimeLock.php` (new), `app/Services/Borrower/CapitalAccess/CapitalAccessStrategyService.php`
- `database/seeders/E2EUnpaidInvoiceSeeder.php`, `database/seeders/E2EResetBorrowerPasswordSeeder.php` (new)
- Tests (new):
  - `tests/Unit/Payment/StripeKeyFieldsSwappedTest.php` (6)
  - `tests/Unit/Borrower/BorrowerLast4SsnKeepsFullSsnTest.php` (4)
  - `tests/Unit/CapitalAccess/StepTimeLockTest.php` (6)

## Testing
- Every new test was red first, then green. Results: `tests/Unit/Payment` 40/40; SSN/Profile filter 108/108; CapitalAccess/StepTimeLock 13/13.
- Live check: `POST /borrower/capital-access/strategies/1/step/27/submit` returned 200 before the fix and 403 "This step is only open during Evening." after it. The application created by the first check was deleted.

## Data (local DB)
- Partner 1's sandbox Stripe keys were swapped back into the right fields.
- Craig's (borrower 1) `ssn_encrypted` is still the 4-digit value written before the fix. The original ciphertext is available from the MySQL binlog; restoring it was held for the owner's approval.

## Open items
- **Check stage/production:** any borrower who saved Personal Info before this fix may have lost their full SSN (look for `ssn_encrypted` decrypting to 4 digits). Partners may have swapped Stripe keys saved before the new validation.
- E2E-017 (raw card numbers from the Authorize.Net, Square and Ecrypt forms) is a frontend/payments change and is still open.
