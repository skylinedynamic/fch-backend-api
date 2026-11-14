# R3-BUG-021: a domain that looks like the host's brand can't be a partner's custom domain

Date: 2026-11-15

## Problem

On partner 89's tenant config, QA saved three borrower domains (200):
- `fourcornerfundng.com`: a misspelling
- `app.fourcornerfunding.com`: the live client portal
- `fourcornerfunding.co`: the brand on another TLD

None was activated. A partner portal on such a domain would pass as Four Corner Funding to a client, which is a phishing risk.

R3-BUG-020's `DomainNotClaimed` refuses only exact host and partner domains:
- **On live,** `app.fourcornerfunding.com` would be refused as a host domain.
- **On stage,** the host runs on `skylinedynamic.dev`, so all three passed.
- **Look-alikes** pass in every environment.

## Solution

**Protected brands** go in `config/domains.php` › `protected_brands` (env `PROTECTED_BRANDS`, comma-separated, default `fourcornerfunding`). They are the same in every environment.

**`DomainNotClaimed::looksLikeBrand()`** refuses a domain if any label below the TLD contains the brand, or is within 2 letters of it (1 for a brand shorter than 10). The label is compared:
- without hyphens
- with look-alike digits read as letters (0→o, 1→l, 3→e, 5→s)

So these are refused:
- the brand on any TLD (`.co`, `.net`)
- under any subdomain (`app.fourcornerfunding.com`, `fourcornerfunding.evil.io`)
- inside a longer name (`fourcornerfunding-login.com`)
- misspelt (`fourcornerfundng`, `f0urcornerfunding`, `four-corner-funding`)

Message: "This domain is too close to the Four Corner Funding name and cannot be used. Choose a domain of your own."

**Not refused:**
- the platform's own subdomains (`<label>.<base website>`), e.g. `wanda.fourcornerfunding.com` on live and `…skylinedynamic.dev` on stage. The claim checks cover those.
- unrelated names (`fourcorners.com`, `cornerstonefunding.com`, `acmefunding.com`)

**Where it applies:** the same places as R3-BUG-020: tenant config (both domains, host and partner screens) and Add Partner.

## Files changed

- `config/domains.php`: `protected_brands`.
- `app/Rules/DomainNotClaimed.php`: the look-alike check, after the host-domain check.
- `lang/en/partner.php`: `validation.domain_lookalike`.
- `tests/Feature/Setting/TenantDomainLookalikeTest.php` (new, 13):
  - QA's three domains and four more look-alikes refused as the borrower domain
  - a look-alike refused as the partner domain
  - four own names still save
  - on live, a partner subdomain of the brand site saves

## Testing

- **Seen failing first:** 8 of the 13 (every refusal). The 5 that save passed before too.
- **`tests/Feature/Setting`, `Partner`, `General`:** 259 passed.
- **Full suite (after R3-BUG-021 to 024):** 5,655 tests, all passed (2 skipped).

## Open items

- **Host approval** (QA's other option) is not built. A partner that really needs such a domain must have it added by hand, or `PROTECTED_BRANDS` adjusted.
- **Existing data:** a partner already holding a look-alike domain is not changed. Check stage and live.
- **Other brands:** add more names to `PROTECTED_BRANDS` if needed, e.g. a product name.
