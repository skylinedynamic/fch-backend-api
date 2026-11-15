# Partner commission tiers — Funding, by funded-deal count

**Date:** 2026-11-15
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain (with Claude Code)

## Problem
Partner › Commissions held one `partners.commission` %: the partner's share of the success fee on every deal. The business wants the partner's Funding commission paid by **funded-deal count tiers**, for example 0–10 → 10%, 11–20 → 12%, 21+ → 15%, with as many rows as needed and % only.

Employee commissions (`compensation_settings`) are **unchanged**, as requested: Per Deal still has a single rate per service.

## Solution
**New table `partner_commission_tiers`.**
- Columns: partner_id (FK, cascade), min_count, max_count (null = and up), percentage (decimal 5,2), sort_order.
- `Partner::commissionTiers()`, model `PartnerCommissionTier`.

**Validation is server-side, with each error keyed to its field** (`App\Http\Requests\Concerns\ValidatesCommissionTiers`):
- the commission is **required**: at least one tier, and every tier needs its %; it can't be cleared;
- the first tier starts at 0 (fixed in the portal);
- each tier starts at the previous end + 1, so there are no gaps or overlaps;
- only the last tier may be open-ended;
- the end is ≥ the start;
- each % is 0–99 (`Partner::COMMISSION_MIN/MAX`).

Errors are keyed `tiers.{j}.min_count`, so the portal shows each one under its input.

**How deals are counted** (decided with the product owner): the partner's funded deals (Closed (Completed)), all-time. Each deal uses the tier of its own position, so earlier deals are never re-priced. `CommissionTierResolver` holds the rules:
- `pick(tiers, position)`
- `partnerPosition(partner, application)`: the application's place among the partner's funded applications, ordered by `funded_date` then `id`; an application not yet funded is the partner's next deal.
- `partnerFundedCount(partner)`

**API:**
- `PUT /admin/partners/{id}/commission`:
  - body `{ tiers: [{min_count, max_count, percentage}] }`; at least one tier (an empty list is refused);
  - response `{ id, commission_tiers, funded_deal_count }`;
  - the host is still refused.
- `GET /admin/partners-details/{id}` returns `commission_tiers` and `funded_deal_count` in place of `commission`.
- Funding application `partner_default_commission` (where the split opens) is now the partner tier for that deal.
- Funding application `partner_commission_setup`, for the Closed (Completed) modal's Partner Commission section:
  - `{ tiers, deal_position, funded_deal_count }`;
  - `tiers` is empty when the partner has no commission set;
  - null for a host client;
  - worked out once per resource, together with `partner_default_commission`.

**Activity log** (Added / Updated; there is no Removed, since the commission can't be cleared): "from" and "to" are a summary line. A single 0+ tier reads "25%"; several tiers read "0-10: 20%, 11+: 27.5%".

**Data migration** `2026_11_15_000002`: a non-null `partners.commission` becomes one 0+ tier. The column is no longer read.

## Files changed
**Database**
- `database/migrations/2026_11_15_000001_create_partner_commission_tiers_table.php`: new table.
- `database/migrations/2026_11_15_000002_move_partner_commission_into_tiers.php`: copies the old commission.

**Models**
- `app/Models/Partner/PartnerCommissionTier.php`: new.
- `app/Models/Partner/Partner.php`: `commissionTiers()`.

**Requests**
- `app/Http/Requests/Concerns/ValidatesCommissionTiers.php`: new, the range checks.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdatePartnerCommissionRequest.php`: `tiers` instead of `commission`.

**Services**
- `app/Services/FCH/Common/Commission/CommissionTierResolver.php`: new.
- `app/Services/FCH/Partner/PartnerService.php`:
  - `updateCommission(Partner, array $tiers)`
  - `commissionOf()`, `tierRows()`
  - summary-based activity log

**Controller and resources**
- `app/Http/Controllers/Api/V1/FCH/Admin/Partner/PartnerController.php`: returns `commissionOf()`.
- `app/Http/Resources/FCH/Partner/PartnerDetailsResource.php`: `commission_tiers`, `funded_deal_count`.
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`: `partner_default_commission` comes from the tier; new `partner_commission_setup`.

**Language files**
- `lang/en/commission.php`: `tier.*` messages.
- `lang/en/partner.php`: `validation.commission.required_tiers` / `required`; the unused `commission_removed` strings removed.

## Testing
- Rewritten: `tests/Feature/Partner/PartnerCommissionApiTest.php` (save, required and can't be cleared, 12 invalid-tier cases each under its own field, host refused, permissions, activity summaries, details, the split default by deal position, only the partner's own deals counted, `partner_commission_setup` set / not set / host, the data migration).
- New: `tests/Unit/Services/CommissionTierResolverTest.php`.
- Partner tests and the resolver unit test: **167 passed**.
- Full suite: see the note below.
- `php artisan migrate` on the local DB: both migrations ran. No partner had a commission set, so 0 rows were copied.

## Open items
- Drop `partners.commission` once nothing reads it.
- `PUT partners/{id}/commission` still has no permission middleware (the 2026-11-11 decision stands).
- Applications closed without a `funded_date` sort first when positions are worked out.
