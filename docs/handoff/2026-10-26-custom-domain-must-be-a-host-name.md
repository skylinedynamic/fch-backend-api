# Custom Domain — a domain must be a host name, is saved cleaned, and the borrower domain stays optional (BUG-121)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-121** (PP-SET-07): Settings › Custom Domain saved `not a domain` as the Borrower Application Domain with Enable Custom Domain on (`POST /common/partner/tenant-config` → 200). That partner's borrower links and mail would use the bad host.

`UpdateTenantConfigRequest` checked only required / string / max / not taken:
- **No host-name format check.**
- The value was saved as typed. `https://`, `www.` and upper case were stripped only for the duplicate check, while the domain resolver (`getPartnerIdFromOrigin`) compares the cleaned host exactly, so `https://App.example.com` could never match.
- The duplicate check excluded `auth()->user()->entity_id`, which is the employee id for a staff user. A staff user saving the partner's current domains got "already taken".

QA's step 2 (empty borrower domain → no message) did not reproduce locally. The API answered 422 "The borrower domain field is required." Stage may run older code.

## Solution
- **Format:** `partner_domain` and `borrower_domain` must match a host name (letters, digits and inner hyphens in dot-separated labels, alphabetic TLD, e.g. `app.example.com`). Otherwise: "Enter a valid domain, e.g. admin.example.com." / "…app.example.com."
- **`prepareForValidation`** cleans both domains: trim, lower case, no `http(s)://`, no `www.`, no trailing slash; empty becomes null. The cleaned value is what is validated and saved.
- **Borrower domain is optional,** even with the custom domain on (as decided). It is validated only when filled. The partner domain is still required when the custom domain is on, with its own message: "Enter the partner portal domain."
- **The API is the only validator:** the admin screens dropped the browser's `required`, as decided.
- **Partner id** for the duplicate check: the route `id` on the host's admin screen, otherwise the user's `partner_id`, never `entity_id`.
- The restricted / already-taken checks are unchanged in effect, now on the cleaned value.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdateTenantConfigRequest.php`: host-name rule, cleaning, optional borrower domain, partner id, messages.
- `lang/en/partner.php`: `validation.partner_domain.host`, `validation.partner_domain.portal_required`, `validation.borrower_domain.host`.
- `tests/Feature/Setting/TenantDomainValidationTest.php`: new, 17 tests.

## Tests
- **New** (15 failed first):
  - six non-host values (spaces, no dot, underscore, leading hyphen, path, empty label) are refused for each domain with its message, and the stored value is unchanged;
  - an empty borrower domain saves as null with the custom domain on;
  - an empty partner domain is still refused with "Enter the partner portal domain.";
  - `HTTPS://www.Portal.Example.com/` saves as `portal.example.com`;
  - a staff user can save the partner's current domains;
  - another partner's domain is still refused.
- `tests/Feature/Setting` + `tests/Feature/Partner`: 157 passed.

## Open items
- **Existing bad values are not cleaned:** if stage already holds `not a domain`, it stays until the partner saves again (QA restored theirs).
- **QA sheet:** BUG-121 → Fixed after the stage retest. The borrower domain is now optional by decision, so QA's step 2 expectation ("domain required") no longer applies.
