# Certificate prefix rule removed (F123, was D7)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F123 (with F122, closes D7)

## Problem
Caddy asks `GET /api/caddy/domain-check?domain=…` before it issues an on-demand TLS certificate. The check said OK to **any** domain starting with `api.`, `partner.` or `app.` (`str_starts_with`), whatever came after.

Anyone could point `app.<anything>.com` at the server and have a certificate requested from Let's Encrypt. Enough of these could exhaust the Let's Encrypt rate limits the real partners need for new and renewed certificates. Their sites would then show as not secure.

## Solution
**Decision (user):** a name starting with those prefixes is not enough; the domain has to be in the database.

The prefix checks are removed. The check now says OK only for:
1. the reserved system domains in `config/domains.php` (F122);
2. the exact system list (`BASE_WEBSITE`, `www.`, `api.`, `partner.`, `app.` + `BASE_WEBSITE`);
3. a partner subdomain in the database (`partner_subdomain`);
4. a partner or borrower custom domain in the database with `active_custom_url` on.

Everything else gets 403. A prefixed partner domain such as `app.acme.com` still gets a certificate when it is in the database and active.

## Files changed
- `app/Http/Controllers/DomainResolverController.php`
- `tests/Feature/Website/PublicWebsiteApiTest.php`

## Tests
- `Feature/Website/PublicWebsiteApiTest::an_api_app_or_partner_prefix_alone_gets_no_certificate`. It failed first with 200, then passed. It checks that:
  - `app.random-site.test`, `api.attacker.test` and `partner.nobody.test` get 403;
  - `app.acme.test` gets OK as an active partner domain;
  - `partner.beta.test` gets OK as a partner subdomain;
  - `app.gamma.test` gets 403, because it is in the database but not active.
- The F122 reserved-domain test and the existing known-domains test still pass.
- Suites run: fch-api full suite 2312 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- **Behaviour change for inactive custom domains.** A partner whose custom domain starts with `api.`, `app.` or `partner.` but has **Active Custom URL off** no longer gets a certificate on the prefix alone. To find any such partner, run this on production; turn Active Custom URL on for any that are really in use:
  `SELECT id, company_name, partner_domain, borrower_domain, active_custom_url FROM partners WHERE deleted_at IS NULL AND (active_custom_url = 0 OR active_custom_url IS NULL) AND (partner_domain LIKE 'api.%' OR partner_domain LIKE 'app.%' OR partner_domain LIKE 'partner.%' OR borrower_domain LIKE 'api.%' OR borrower_domain LIKE 'app.%' OR borrower_domain LIKE 'partner.%');`
- **Domain env values.** `BASE_WEBSITE` and the `BASE_*` domain values must be right in every environment (deploy checklist step 4).
