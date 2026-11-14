# R3-BUG-020: a portal domain can't be the host's or another partner's

Date: 2026-11-15

## Problem

Host Admin › QA WL partner 89 › tenant config (`POST admin/partners/tenant-config/89`). QA tried two values as `borrower_domain`:
- `admin-stage.skylinedynamic.dev`, the host's admin portal
- `wanda.skylinedynamic.dev`, Wanda's partner portal

Both saved: "Tenant configuration updated successfully." Only a borrower domain matching another partner's borrower domain was refused. Activating the domain could have taken over admin-stage's routing (High / P1).

`UpdateTenantConfigRequest`'s `$notTaken($column)` had three gaps:
- **Same column only.** It compared the value with the same column only: a borrower domain with other borrower domains, a partner domain with other partner domains.
- **Not the real host domains.** It refused only `<reserved word>.<base_website>` (e.g. `admin.skylinedynamic.dev`), not the host's real portal URLs (`admin-stage…`, `client-stage…`, the API).
- **Own portals.** It let a partner's two portals share one domain.

`StorePartnerRequest` (Add Partner) compared the new subdomain with `partner_domain` only, and didn't check `borrower_domain` at all.

## Solution

**New rule `App\Rules\DomainNotClaimed`.** A domain is refused when it is:
- **A host domain:** the same reserved list the edge resolver uses (`config/domains.php` › `reserved`):
  - the website (`BASE_WEBSITE`)
  - the API (`BASE_API_URL`)
  - the partner/admin portal (`BASE_PARTNER_PORTAL`)
  - the client portal (`BASE_BORROWER_PORTAL`)
  - plus the API's own `APP_URL` and a reserved name on the website domain (`admin.`, `client.` …)
- **Another partner's domain**, partner OR borrower, where the partner is not deleted. The resolver matches both columns.
- **The same partner's other portal domain.** Message: "The borrower portal needs its own domain, different from the partner portal domain."

Domains are compared the way the resolver compares them: no scheme, "www.", port or path, and lower case.

**Where it is used:**
- **Tenant config:** both domains, on the host's screen (`admin/partners/tenant-config/{id}`) and on the partner's own (`common/partner/tenant-config`).
  - The own-portal check uses the saved partner domain when the request doesn't send one.
- **Add Partner:** the full subdomain (`<label>.<base_website>`) and the optional borrower domain.

**Admin:** no change. Both forms already show the API's field errors:
- Partners › Tenant Config (`TenantConfigSection.jsx`)
- Settings › Custom Domain (`TenantDomainConfig.jsx`)

## Files changed

- `app/Rules/DomainNotClaimed.php` (new).
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdateTenantConfigRequest.php`: uses the rule for both domains.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/StorePartnerRequest.php`: uses the rule for the subdomain and the borrower domain.
- `lang/en/partner.php`: `validation.borrower_domain.same_as_partner`.
- `tests/Feature/Setting/TenantDomainClaimTest.php` (new, 16):
  - each host domain (admin portal, client portal, API, website, reserved name) refused as the borrower and as the partner domain
  - QA's case: another partner's partner domain as the borrower domain
  - the reverse: another partner's borrower domain as the partner domain
  - a partner's two portals can't match
  - its own domains still save
  - a deleted partner's domain is free
  - Add Partner refuses `admin-stage` and another partner's borrower subdomain

## Testing

- **Seen failing first:** 12 of the 16. The other 4 (reserved name ×2, own domains, deleted partner) pin behaviour that stays.
- **`tests/Feature/Setting`, `tests/Feature/Partner`:** 207 passed.
- **Full suite:** run after R3-BUG-017 to 020.

## Open items

- **Stage env:** the rule reads the host domains from `config/domains.php`, the same list the domain resolver already relies on. Stage's `BASE_PARTNER_PORTAL` / `BASE_BORROWER_PORTAL` / `BASE_API_URL` should hold the real stage hosts (`admin-stage…`, `client-stage…`, `api-stage…`).
- **Existing data:** a partner that already holds a clashing domain is not changed. QA restored partner 89's to null. Check stage for any other partner whose borrower domain equals a host domain or another partner's domain.
