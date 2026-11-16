# Partner commission type per tier: Percentage or Flat Amount

**Date:** 2026-11-16
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain (with Claude Code)

## Problem
Partner › Commissions tiers (2026-11-15) could only be a % of the success fee. The business wants each tier to be either **Percentage** or **Flat Amount** (a fixed $ per deal), chosen per row.

Two follow-ups came from the Closed (Completed) modal:
- When the deal falls in a tier, the admin / partner split must come from that tier and not be editable.
- A flat tier must not be shown or saved as a rounded %. For example, $500 of a $30,000 fee was saved as 1.67%, which pays **$501**.

## Solution
**Each tier has a type** (`partner_commission_tiers.commission_type`: `percentage` (default) or `flat`) and holds only that type's value: `percentage` or the new `amount` (decimal 10,2). The other value is null. Existing tiers become percentages.
- Constants: `PartnerCommissionTier::TYPE_PERCENTAGE`, `TYPE_FLAT`, `TYPES`, `AMOUNT_MIN` / `AMOUNT_MAX`.
- `UpdatePartnerCommissionRequest`:
  - takes `tiers.*.commission_type` (optional, defaults to percentage);
  - a percentage tier needs `percentage` (0–99), a flat tier needs `amount` (0–99,999,999.99);
  - uses `exclude_if` / `exclude_unless`, so only the tier's own value is validated and kept;
  - range checks are unchanged.
- `tierRows()` returns `commission_type`, `percentage`, `amount`. This shape is used by the partner details, the PUT response and `partner_commission_setup`.
- Activity log summary per tier: "0-10: $1,500, 11+: 12.5%"; a single 0+ tier reads "$500" or "25%". Switching a tier's type is logged as a change.
- `partner_default_commission` is null when the deal's tier is flat. The portal works out that tier's share from `partner_commission_setup`.

**The split follows the tier, on the server** (`App\Services\FCH\Common\Commission\CommissionSplitCalculator`):
- `tierShareFor(application)` returns the partner's tier for this deal, or null for a host client or when no tier covers the deal.
- `split(totalAmount, type, %, flat)`:
  - percentage: the % of the fee, as before;
  - flat: exactly the $, capped at `Partner::COMMISSION_MAX` % of the fee (the admin keeps ≥ 1%). The % is derived from the amount.
- `save-commission`: the split sent by the client is **always ignored**. When a tier applies, the split comes from the tier. Otherwise (no tier for the deal, or a host client) the admin keeps the whole success fee (100 / 0). The success log records the saved split, not the sent one.
- New `commissions.partner_commission_type` and `partner_commission_flat_amount` keep how the share was set.
- On close (`status-update` → Closed (Completed)), the amounts are recomputed at the final funded amount through `split()`. A flat share stays its exact $, and its % is updated to match.
- The funding application details return `partner_commission_type`, so the Closed summary shows a flat share in $, without a %.
- Commission details (`CommissionDetailsResource`) return `partner_commission_type` (owners only). A flat partner payout (`CommissionPayoutService::forPartner`) is `payout_type: Flat Amount` with no `rate`.

## Files changed
- `database/migrations/2026_11_16_000001_add_commission_type_to_partner_commission_tiers.php`: tier `commission_type` and `amount`; `percentage` becomes nullable.
- `database/migrations/2026_11_16_000002_add_partner_commission_type_to_commissions.php`: `partner_commission_type`, `partner_commission_flat_amount`.
- `app/Models/Partner/PartnerCommissionTier.php`: type constants, `amount`, `isFlat()`.
- `app/Models/Commission/Commission.php`: new fillable / casts.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdatePartnerCommissionRequest.php`: per-tier type rules and messages.
- `app/Services/FCH/Partner/PartnerService.php`: per-tier normalize, `tierRows()`, summary.
- `app/Services/FCH/Common/Commission/CommissionSplitCalculator.php`: new.
- `app/Services/FCH/Admin/Commission/CommissionService.php`: split from the tier on save.
- `app/Services/FCH/Common/Application/RequestApplicationService.php`: recompute on close via `split()`.
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`: no % default for a flat tier.
- `lang/en/partner.php`: `validation.commission.type` and `amount_*`.

## Testing
- **Full regression (end of day):** whole fch-api suite **5554 passed, 2 skipped, 0 failed**.
- New: `tests/Feature/Partner/PartnerCommissionTypeApiTest.php` (mixed types saved, only the tier's own value kept, no type = percentage, 8 invalid cases each under its field, flat not capped at 99, $ in the activity log, type switch logged, details, the funding application default and setup). 15 tests.
- New: `tests/Feature/Commission/CommissionSplitFromPartnerTierTest.php` (flat pays exactly $500 not $501, a percentage tier overrides the sent split, no tier gives the partner nothing whatever is sent, admin keeps 1%, closing at a new funded amount keeps the flat $, host client, details carry `partner_commission_type`, commission details and payout for flat vs percentage). 9 tests. Commission + CommissionPayout suites: **88 passed**.
- Existing `PartnerCommissionApiTest` and `CommissionTierResolverTest`: unchanged and passing.
- Updated: `SaveCommissionAndCreditRequestAdminApiTest::the_commission_is_split_between_host_and_partner` now gives the partner a 60% tier, since a typed 60% is no longer kept.
- Partner, Commission, CommissionPayout, FundApplication, Application and Unit/Services suites: **748 passed, 1 skipped** (the skip was already there).
- `php artisan migrate` on the local DB: both migrations ran.

## Open items
- A commission saved **before** this change has no `partner_commission_type`. It is recomputed from its % on close, as before, unless it is saved again (then the tier / no-tier rule applies).
- Business rule to confirm: a partner with **no** commission tier for a deal now gets 0% of it.
- The split follows the tier the deal has **when it is saved / closed**. Changing a partner's tiers later does not re-price commissions already saved.
- `PUT partners/{id}/commission` still has no permission middleware (the 2026-11-11 decision stands).
