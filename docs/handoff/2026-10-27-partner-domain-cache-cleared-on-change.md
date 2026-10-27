# A partner domain's cached owner is cleared when it changes (BUG-118)

**Date:** 2026-10-27
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
QA defect log **BUG-118** (OP-PART-35). A host created the Certified partner "QA-TEST Cert Two Co" with subdomain `qacerttwob`. Its login page footer read "(c) 2026 . All rights reserved.", with an empty company name, for about an hour and then corrected itself.

Branding finds a portal domain's partner and caches its **id** for an hour (`domain_partner_id:{domain}`, plus `domain_portal:{domain}`). Nothing ever cleared that cache:
- If the cached partner was deleted (for example, a domain reused after a test partner was removed), `Partner::find()` found nothing and `/common/branding` returned empty branding, so there was no `site_name`.
- A domain moved to another partner kept the old partner's brand for an hour.
- A white-label domain opened before the partner had it showed the host's brand for an hour.

Also, `getPartnerIdFromOrigin()` called `Cache::remember()` on the same key with a TTL of 0, which in Laravel only deletes the key.

## Solution
- New `App\Support\Branding\PartnerDomain`. It owns the cache keys, the domain normalisation, the lookup (the owning partner, else the host) and the cache clearing.
- `Partner::booted()` clears the cache for both the old and the new domains when a partner is created, when its `partner_domain` / `partner_subdomain` / `borrower_domain` change, and when it is deleted or restored.
- `BrandingController::resolvePartner()`: if the cached id no longer finds a partner (for example, removed without model events), it forgets the key and looks the domain up again.
- `getPartnerIdFromOrigin()` uses `PartnerDomain::lookup()`, uncached as before, and no longer deletes the branding cache key.
- CLAUDE.md: new coding rule. No fully-qualified class names in code; import with `use`.

## Files changed
- `app/Support/Branding/PartnerDomain.php`: new.
- `app/Models/Partner/Partner.php`: `booted()` clears domain caches.
- `app/Http/Controllers/Api/V1/Global/Branding/BrandingController.php`: shared keys and lookup; looks again when the cached partner is gone.
- `app/Helpers/helpers.php`: `getPartnerIdFromOrigin()` uses `PartnerDomain::lookup()`.
- `CLAUDE.md`: the `use`-import rule.

## Tests
- Added: `tests/Feature/Branding/NewPartnerDomainBrandingTest.php`, 7 tests.
  - New Certified subdomain: host name. Opened before it was saved: host name. New white-label subdomain: its own name. These 3 passed throughout.
  - These failed before the fix:
    - a subdomain reused after its partner was deleted (null name)
    - a subdomain moved to another partner (old brand)
    - a white-label subdomain opened before it was saved (host brand)
  - Covers the "cached partner no longer exists" path. It fails without that code (checked by removing it).
- Suites run: `tests/Feature/{Branding,Partner,Configuration,Application,Commission,CommissionPayout}`, `SmallEndpointsApiTest` and `HelpersTest`: 289 passed.

## Open / follow-up
- Portal side: `react-fch` `Auth.jsx` no longer prints "(c) 2026 ." when there is no name. See react-fch `docs/handoff/2026-10-27-login-footer-without-a-brand-name.md`.
- Older code still has inline fully-qualified names (e.g. `RequestApplicationService` line ~396, `helpers.php` ~596). Change them under the new CLAUDE.md rule when those lines are touched.
- Re-test BUG-118 on stage: create a partner on a subdomain a deleted partner used, and open its login page straight away.
