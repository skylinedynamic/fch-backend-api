# Configuration guard — a Certified partner's payment flag is worked out from its own gateways (BUG-115)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-115** (PP-CERT-04): a **Certified** partner with all six gateways Inactive (Settings › Payment Integration) opened Clients › Add Client and Leads › create with no **Configuration Incomplete** modal. The client was saved, and its $2,497 enrollment invoice had no gateway to be paid with. A White-Label partner in the same state did get the modal.

The admin guard (`ConfigurationGuardModal`) already stops a Certified partner when `is_fresh_payment` is missing. But `CommonController::getTheme()` worked that flag out for `getConfigPartner()`, which returns **the host** for a Certified partner, because it trades under the host's brand. So the flag said whether the host had an active gateway, which is always true.

## Solution
- In `getTheme()`, `is_fresh_payment` is now worked out for the **signed-in user's own partner** (`auth()->user()?->partner`), falling back to the config partner when nobody is signed in.
- Branding, email and site-settings flags still use `getConfigPartner()`; a Certified partner's branding is the host's.
- The host and White-Label partners resolve to themselves either way, so nothing changes for them.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/CommonController.php`: the payment flag uses the signed-in partner.
- `tests/Feature/Configuration/PaymentConfigurationFlagTest.php`: new, 5 tests.

## Tests
- **New** (`GET /api/v1/common/partner-theme`, host with an active gateway):
  - a Certified partner whose gateways are all inactive → `is_fresh_payment` null (failed first: it was `true`);
  - a Certified partner with no gateway at all → null (failed first);
  - a Certified partner with an active gateway → true;
  - White-Label, inactive → null and active → true;
  - host → true.
- `--filter "Theme|Branding|Configuration|Payment"`: 620 passed.
- **Full suite:** 3,703 tests, 1 failure, 2 skipped. The failure is not from this change: `NavigationMapTest` › `borrower_support:capital_access` expects a `/capital-access` route in client-fch `src/App.jsx`, but client-fch commit 296e6b2 moved Capital Access to `/credit-card-stacking` (old links redirect).

## Open items
- `NavigationMapTest` / the assistant navigation map should point Capital Access at `/credit-card-stacking`. Separate from BUG-115.
- A partner whose config already loaded keeps its cached flags until the theme is fetched again (next login or reload).
- QA sheet: BUG-115 → Fixed after the stage retest (Certified partner, all gateways inactive → modal on Add Client and Leads).
