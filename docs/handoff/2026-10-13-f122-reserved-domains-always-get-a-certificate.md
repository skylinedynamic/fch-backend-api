# Reserved system domains always get a certificate go-ahead (F122, part of D7)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F122 (part of D7; the prefix rule stays open)

## Problem
Caddy asks `GET /api/caddy/domain-check?domain=…` before it issues an on-demand TLS certificate. The check refused the platform's own domains with 403 before anything else. These are the four in the `reserved` list in `config/domains.php`:
- `BASE_WEBSITE`
- `BASE_API_URL`
- `BASE_PARTNER_PORTAL`
- `BASE_BORROWER_PORTAL`

Their certificates therefore depended entirely on being configured in Caddy by hand.

## Solution
**Decision (user):** the domains in `config/domains.php` must always get a certificate.

- `DomainResolverController::caddyCheck()` now answers **200 OK** for any domain in the reserved list, instead of 403.
- The match is after lower-casing and trimming a trailing dot or `www.`, as before. Empty config values are ignored.
- `resolve()` is unchanged. The reserved list still means "not a partner tenant" there.

## Files changed
- `app/Http/Controllers/DomainResolverController.php`
- `tests/Feature/Website/PublicWebsiteApiTest.php`

## Tests
- `Feature/Website/PublicWebsiteApiTest::the_reserved_system_domains_always_get_a_certificate_go_ahead` checks all four reserved domains with a set test config, including a mixed-case variant with a trailing dot. It failed first with 403, then passed.
- The existing `caddy_gets_a_certificate_go_ahead_only_for_known_domains` still passes: partner subdomain, active custom domain, inactive custom domain and unknown domain all behave as before.
- Suites run: fch-api full suite 2311 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- **Update:** the prefix rule was removed in F123 (`2026-10-13-f123-certificate-prefix-rule-removed.md`). The text below describes why.
- **D7, the prefix rule (now removed in F123).** At the time of F122, any domain starting with `api.`, `partner.` or `app.` still got a go-ahead, so for example `app.anything.com` could have a certificate requested. That allowed certificate-issuance abuse and exhaustion of the Let's Encrypt rate limit.
- **The production check from F123 still applies.** It finds partners whose prefixed custom domain is inactive and so no longer gets a certificate:
  `SELECT id, company_name, partner_domain, borrower_domain, active_custom_url FROM partners WHERE deleted_at IS NULL AND (active_custom_url = 0 OR active_custom_url IS NULL) AND (partner_domain LIKE 'api.%' OR partner_domain LIKE 'app.%' OR partner_domain LIKE 'partner.%' OR borrower_domain LIKE 'api.%' OR borrower_domain LIKE 'app.%' OR borrower_domain LIKE 'partner.%');`
- **Check the env values in every environment.** `BASE_WEBSITE`, `BASE_API_URL`, `BASE_PARTNER_PORTAL` and `BASE_BORROWER_PORTAL` must be the real domains for that environment. The reserved list comes from them, and their defaults are the production domains.
