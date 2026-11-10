# Embed preview blocked once Allowed Domains is set

2026-11-08

## Problem
On stage, `skylinedynamic.dev` was saved in the host's Embedded Form > Allowed Domains. After that, the live preview on Embed Form Settings in the admin panel (`admin.skylinedynamic.dev`) showed "skylinedynamic.dev refused to connect".

The form page sends `Content-Security-Policy: frame-ancestors <Allowed Domains>` (BUG-092). The admin panel is a different origin and was not in that list, so the browser refused to show the form inside the preview. The embed code itself kept working on the listed sites.

## Solution
`EmbedFormService::frameAncestors()` now adds the partner's own admin-panel origin(s) whenever the Allowed Domains list is non-empty:

- **Host partner** (or a config with no partner): `BASE_PARTNER_PORTAL` (`config('app.frontend_partner_url')`).
- **Any other partner:** its own `partner_domain` and `partner_subdomain` from the `partners` table.

The other rules are unchanged:
- **Empty list:** still sends no CSP (unrestricted).
- **Other sites:** an unlisted site still cannot frame the form.
- **`www.` variants:** these are added only for the domains the partner typed.

`frameSource()` turns each address into a CSP source made of scheme, host and port:
- the path is dropped
- a stored value with no scheme (`localhost:5174`, `exodias.skylinedynamic.dev`) stays scheme-less, so the browser matches it against the form page's own scheme

## Files changed
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `frameAncestors()` appends `platformFrameOrigins()`, and a new `frameSource()` helper.
- `tests/Feature/EmbedForm/EmbedFormFramingTest.php`: config set as on stage, expectations updated, and new cases for:
  - the portal is allowed
  - a scheme-less portal address keeps its port
  - a non-host partner uses its domain or subdomain and not the portal
  - a subdomain-only partner
  - the host uses only the portal

## Testing
- `EmbedFormFramingTest`: 8 passed (25 assertions); red first, then green.
- Pint applied to both files.
- Full suite: 4381 passed, 2 skipped (14618 assertions).

## Open items
- **Stage env:** stage must have `BASE_PARTNER_PORTAL=https://admin.skylinedynamic.dev`.
- **Partner columns:** a partner whose `partner_domain`/`partner_subdomain` is empty gets no portal origin, so its preview stays blank while Allowed Domains is set.
- **Not deployed:** not committed or pushed yet.
