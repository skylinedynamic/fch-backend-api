# Security headers: the API's pages can no longer be framed by other sites (BUG-013, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-013** (R2-OP-SEC-03, High/P1): the admin and borrower portals could be shown in an iframe on any site (clickjacking) — no `X-Frame-Options`, no `Content-Security-Policy`, no `Strict-Transport-Security`. The defect log showed it as Fixed; nothing was in place.

The API had the same gap. `api.skylinedynamic.dev` serves pages of its own: the system dashboard's `/login` and `/dashboard/*`, which include backup download, restore and delete. Any site could frame them.

## Solution
- **Portals (server, done on staging 31-Oct):** `/etc/nginx/snippets/fch-security-headers.conf` holds `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Strict-Transport-Security: max-age=86400`. It is included at server level in both portal blocks and again in their static-asset `location` (which has its own `add_header`, so it does not inherit). Verified from outside on `admin.`, `wanda.`, `client.`, `wanda-borrower.` — pages, deep links and JS assets — with link previews (BUG-012) unchanged. `'self'` is the requesting domain, so partners' custom domains are covered by the same config.
- **API (this change):** new global middleware `SecurityHeaders` sets `X-Content-Type-Options: nosniff` and `Referrer-Policy` on every response, and `X-Frame-Options: SAMEORIGIN` + `Content-Security-Policy: frame-ancestors 'self'` on every response except the routes in `SecurityHeaders::FRAMEABLE_ROUTES`. That list holds the embedded form (`embed-form.show`, `/form/{key}`), which is made to be framed on partners' sites and keeps its own rule (BUG-092): its Allowed Domains, or anywhere when there are none. A response that already has a `Content-Security-Policy` keeps it.
- HSTS is not set by the API; the edge in front of it handles TLS.

## Files changed
- `app/Http/Middleware/SecurityHeaders.php` — new.
- `bootstrap/app.php` — appended to the global middleware stack.
- `tests/Feature/General/SecurityHeadersTest.php` — new: `/login`, a 401 and a 404 API response carry the headers; the embedded form has no `X-Frame-Options`, keeps its Allowed Domains as `frame-ancestors`, and with no list has no framing rule.

## Testing
- `SecurityHeadersTest` failed first (4 failures: headers missing), then passed.
- `SecurityHeadersTest` + `tests/Feature/EmbedForm` — OK, 147 tests.
- Full PHPUnit suite — OK, 4288 tests, 14289 assertions (2 skipped).

## Open items
- **Production:** add the snippet and the two `include` lines to both portal server blocks, then `sudo nginx -t && sudo systemctl reload nginx`.
- Files under `/storage` are served by Nginx directly and get none of these headers. They will be served through Laravel once uploads move to the private disk (BUG-015/BUG-016, planned separately). The portals' Resource Hub frames resource PDFs from `/storage`, so that route must not get `SAMEORIGIN` when it moves.
- HSTS is one day (`max-age=86400`), without `includeSubDomains`, because partners' custom domains are theirs. Raise it once all domains are confirmed HTTPS-only.
