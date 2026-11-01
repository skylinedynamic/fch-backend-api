# Credit score validation — FICO and business credit score

**Date:** 2026-10-15 · **Branch:** `bugfix/all-bugfix` (uncommitted)

## Problem
Typed-in credit scores had no consistent check. The borrower's FICO and every FICO threshold compared against it were `numeric`, `integer|min:0`, digits only, or 300–850 on one path and `min:0` on another; the business credit score (PAYDEX) was plain `numeric`. So a client could be saved with FICO 9000 or a business score of -5 / 12.5, a credit account with a typical FICO minimum of 0, and a lender minimum of FICO 1 — and lender matching compares these values. Local data already holds a credit account with `typical_personal_fico_min = 0`.

## Solution
Server-side only (no portal validation, per the user). Agreed scope:
- **Typed-in score fields only.** Dropdown fields — the self-reported ranges on the funding application and the pre-qualification bands — are unchanged.
- **FICO** (`App\Rules\FicoScore`, new): a whole number from 300 to 850 (bounds from `funding_application.credit_score`, which the embedded form and score bands already use). Applies to the borrower's score and to the FICO thresholds.
- **Business credit score** (`App\Rules\BusinessCreditScore`, new): a whole number of 0 or more, no upper bound (0 counts).
- A whole number may arrive as `720`, `"720"`, `720.0` or `"720.00"` (`minimum_fico_requirement` is a decimal column and echoes back that way); `"720.5"`, text, ranges and exponents are refused.
- Old out-of-range values are refused when the record is next saved (no migration).
- Readiness scoring (`config/funding.php` business credit `max_score` 850) left unchanged, per the user.

| Field | Request(s) | Was | Now |
|---|---|---|---|
| `fico_score` | `StoreBorrowerRequest`, `UpdateBorrowerRequest`, `UpdateBorrowerBusinessFinancialsRequest` | `numeric` / `integer min:0` | `FicoScore` |
| `paydex_score` | same three | `numeric` / `integer min:0` | `BusinessCreditScore` |
| `personal_credit_score` (as `new_value`) | `Borrower\Profile\StoreChangeRequest` | `required\|integer` | `FicoScore` |
| `credit_score` | `SubmitEmbedFormRequest` | `integer` 300–850 | `FicoScore` (same bounds) |
| `lender_visibilities.*.min_fico` | `Store/UpdateFundingSequencingRequest`, `UpdateFundingSequencingVisibilityRequest` | `integer min:0 digits_between:1,8` | `FicoScore` |
| `lender_visibilities.*.min_business_credit_score` | same three | `integer min:0 digits_between:1,8` | `BusinessCreditScore` |
| `typical_personal_fico_min` | `Store/UpdateCreditAccountRequest` | `regex:/^[0-9]+$/` | `FicoScore` |
| `minimum_fico_requirement`, `credit_accounts.*.minimum_fico_requirement` | `Store/UpdateCapitalAccessRequest` | `min:0` / 300–850 / any integer | `FicoScore` |

Required / nullable is unchanged on every field.

## Files changed
- `app/Rules/FicoScore.php`, `app/Rules/BusinessCreditScore.php` (new).
- `lang/en/common.php` — `fico_score_invalid`, `business_credit_score_invalid`.
- 14 request classes listed above.
- Tests:
  - Unit: `tests/Unit/Validation/FicoScoreTest.php`, `BusinessCreditScoreTest.php` (new) — bounds, decimals, text, ranges, empty, message, bounds from config.
  - Unit (updated): `tests/Unit/EmbeddedForm/SubmitEmbedFormRequestTest.php` — its copy of the `credit_score` rule now uses `FicoScore`.
  - Integration: `tests/Feature/Validation/IdentifierFormatRuleWiringTest.php` — every field above uses its shared rule (+ change-request `personal_credit_score`).
  - Feature (HTTP): `Borrower/ClientCreditScoresTest.php` (new, business financials), `Borrower/BorrowerRecordsApiTest.php` (client create), `Borrower/BorrowerProfileApiTest.php` (change request), `Funding/FundingCatalogueAdminApiTest.php` (sequencing create + visibility), `CreditBuilder/AdminCreditBuilderCatalogueApiTest.php` (credit account create/update), `CreditBuilder/HostCreditBuilderApiTest.php` (capital access create/update, `"700.00"` accepted).
  - Feature (updated): `FundingCatalogueAdminApiTest::a_lenders_visibility_rules_are_replaced_one_lender_at_a_time` posted `min_fico => 1` for the lender that must be ignored; now 610 (valid, still different from the stored 650), same assertion.
  - E2E (admin, `Fch-admin-frontend-react`): `e2e/sidebar/clients/client-credit-scores.live.spec.ts`, `e2e/sidebar/credit-builder/credit-accounts/credit-account-fico-error.live.spec.ts` — the real API refuses FICO 900 / business 12.5 / FICO minimum 0, the message shows on the field, nothing is saved.
  - E2E (borrower): none — the borrower portal has no typed score input (its scores are read-only).

## Testing
**Not run** — at the user's request the suites will be run together later. Every changed PHP file passes `php -l`. To run:
- `php artisan test tests/Unit/Validation tests/Unit/EmbeddedForm tests/Feature/Validation tests/Feature/Borrower tests/Feature/Funding tests/Feature/CreditBuilder`, then the full `php artisan test`.
- Admin: `npm run build`, then `npx playwright test e2e/sidebar/clients/client-credit-scores.live.spec.ts e2e/sidebar/credit-builder/credit-accounts/credit-account-fico-error.live.spec.ts --project=setup --project=live`.

## Open items
- **Existing data:** local `credit_accounts.typical_personal_fico_min = 0` will be refused on its next save; check stage/production with read-only counts, e.g. `fico_score NOT BETWEEN 300 AND 850`, `typical_personal_fico_min < 300`, `min_fico NOT BETWEEN 300 AND 850`, `minimum_fico_requirement NOT BETWEEN 300 AND 850`, `paydex_score < 0`.
- The embedded form's old `credit_score.min` / `.max` messages (`embed_form.credit_score_min/max`) and the credit account's `typical_personal_fico_min.regex` message are now unreachable; left in place.
- Embedded-form `credit_score` has wiring + unit coverage but no HTTP test (no existing submission harness for the public form).
- Not in scope, noted: readiness `business_credit.max_score` is 850 while business credit is PAYDEX-style; the self-reported personal score holds raw numbers (`700`, `777`) next to the dropdown ranges in local data.
