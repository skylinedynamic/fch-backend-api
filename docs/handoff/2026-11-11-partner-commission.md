# Partner commission (Partner profile › Commissions)

**Date:** 2026-11-11

## Problem
There was no per-partner commission. When the host closed a partner client's funding application, the success-fee split always opened at Admin 100% / Partner 0%. The agreed rate had to be typed in again for every application.

## Solution
- New column `partners.commission`, `decimal(5,2)`, nullable. It holds the partner's share (%) of the success fee. This is the same meaning as `commissions.partner_commission`, so the stored value can be used as the split as it is.
- `PUT /api/v1/admin/partners/{id}/commission` with body `{ commission: number|null }` (null clears it).
  - The route is in the admin group, so only the host's team can reach it. It has **no permission middleware yet** (decision 2026-11-11: server-side permissions come later). The admin panel still shows the tab only with `view commission`, and lets the user edit only with `update commission`.
  - The value must be 0–99 (`Partner::COMMISSION_MIN/MAX`). The upper limit is 99 because `save-commission` keeps at least 1% for the admin (`admin_commission` min 1).
  - The key is required but may be null.
  - The host partner is refused with a 422 on `commission`, because the admin keeps the whole fee on host clients.
  - Returns `{ id, commission }`.
- `PartnerDetailsResource` now returns `commission` (float or null).
- **Activity log:** a save that changes the rate writes one entry on the partner's activity (`ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE`, subject Partner).
  - Wording: "Commission added" (":user set the commission to 25%."), "Commission updated" (":user changed the commission from 20% to 25%."), or "Commission removed" (":user removed the commission (was 20%).").
  - `properties.changes.commission` holds the old → new rates (`{label, from, to}`), which feeds the sidebar's "View changes" table.
  - Visibility is `internal`, so only the host's staff see it; the partner doesn't.
  - Saving the same value or a refused save logs nothing.
  - A logging failure is written to the error log and never undoes the save.
- `FundingApplicationResource` now returns `partner_default_commission`. It is the application's partner's commission, or null when no rate is set or the partner is the host. It uses the eager-loaded `partner` relation; if that isn't loaded, it runs one small query.
- The save-commission calculation is unchanged. The admin panel fills in the split from this value, and the reviewer can still change it before saving.

## Files changed
- `database/migrations/2026_11_10_000001_add_commission_to_partners_table.php`: adds the column.
- `app/Models/Partner/Partner.php`: fillable, `decimal:2` cast, `COMMISSION_MIN`/`COMMISSION_MAX`.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdatePartnerCommissionRequest.php`: new validation.
- `app/Http/Controllers/Api/V1/FCH/Admin/Partner/PartnerController.php`: new `updateCommission` action.
- `app/Services/FCH/Partner/PartnerService.php`: new `updateCommission` method, which refuses the host, and `logCommissionChange` for the activity entry.
- `app/Models/ActivityLog/ActivityLog.php`: `LOG_TYPE_PARTNER_COMMISSION_UPDATE`.
- `app/Http/Resources/FCH/Partner/PartnerDetailsResource.php`: adds `commission`.
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`: adds `partner_default_commission`.
- `routes/admin.php`: adds the route (admin group, no permission middleware yet).
- `lang/en/partner.php`: validation, success and error messages, plus `partner.activity.commission_*` for the activity wording.

## Testing
- New: `tests/Feature/Partner/PartnerCommissionApiTest.php`. 21 tests, 81 assertions; all fail before the change. Six of them cover the activity entry: added, updated, removed, an unchanged save, a refused save, and visible to host staff but not the partner.
- They cover: set, clear, invalid values (missing, not a number, negative, 99.5, 101), the host refused, an unknown partner (404), saved by a host-team user with no commission permission (no server check yet), refused for outsiders and unauthenticated callers, the value in the details response, and `partner_default_commission` for partner and host clients.
- Related suites all pass: `tests/Feature/Partner` (132), `tests/Feature/ActivityLog` (157), `tests/Feature/CommissionPayout` (49), `tests/Feature/Commission` (29), `tests/Feature/Application` (128).
- The migration was run on the local `fch-migration` database.

## Open items
- Server-side permission for `PUT /admin/partners/{id}/commission` is not enforced yet; to be added later (e.g. `permission:update commission`, which the admin panel already checks).
- Applications that already have a saved split are not changed. The partner's rate only fills the split when none is saved.
- `save-commission` does not fall back to the partner's rate on the server when `partner_commission` is left out. The admin panel always sends both rates for partner clients. Add a server-side fallback if other callers need one.
- Admin panel side: `Fch-admin-frontend-react/docs/handoff/2026-11-11-partner-commission.md`.
