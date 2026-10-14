# Domain resolver (F101, was D8)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F101 (was D8)

## Problem
The `X-Internal-Key` default `fourcornerfunding2026` was committed in config and `.env.example`; with no key set, a request without the header matched.

## Solution
No default; an empty key refuses everyone; constant-time compare. **Ops:** set `INTERNAL_RESOLVER_KEY` in every environment (and on the edge proxy) before deploying.

## Files changed
- `config/domains.php`
- `.env.example`
- `app/Http/Controllers/DomainResolverController.php`
- `tests/Feature/Website/PublicWebsiteApiTest.php`

## Tests
- `Feature/Website/PublicWebsiteApiTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
**Before deploy:** set `INTERNAL_RESOLVER_KEY` (a long random value) in every environment and the same value on the edge proxy. Without it every domain lookup is refused.
