# Embedded Form — only the Allowed Domains may show it in an iframe (BUG-092)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-092** (OP-EMB-05): with Embedded Form › Configuration › Allowed Domains = `https://skylinedynamic.dev` only, the form put in an `<iframe>` on example.com (and on admin.skylinedynamic.dev) rendered and could be filled in.

- The form page (`GET /form/{key}`, `EmbedFormPageController`) sent no framing rule — no `Content-Security-Policy: frame-ancestors`, no `X-Frame-Options` — so any site could show it.
- Allowed Domains was checked only on submit (`EmbedFormService::isOriginAllowed`, from `Origin`/`Referer`). Inside an iframe the form's own page sends that request, so the browser reports the form's host — always permitted — never the site around the iframe. It only stopped a foreign page posting to the API with its own script.

## Solution
- `EmbedFormService::frameAncestors()` builds the sources from the Allowed Domains — **only the listed domains** (as decided): each host with and without `www.`, in the scheme it was typed with (https when none). No `'self'`, no partner portal, no subdomains.
- `EmbedFormPageController::show()` sends `Content-Security-Policy: frame-ancestors <those sources>` when the list is set; the browser then refuses to show the form in an iframe anywhere else. An empty list sends nothing — unrestricted, as before.
- Opening the shareable link directly is not framing and keeps working.
- The submit Origin check is unchanged, as a second layer against direct posts.

## Files changed
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php` — `frameAncestors()`.
- `app/Http/Controllers/Web/EmbedFormPageController.php` — the header.
- `tests/Feature/EmbedForm/EmbedFormFramingTest.php` — new, 3 tests.

## Tests
- New: only the listed domain (with/without www) — not `'self'`, the portal or example.com; several domains however typed (path, www, http); no list → no header. The two header tests failed first, pass after.
- `tests/Feature/EmbedForm`: 139 passed. Full suite: see the session report.
- Real browser (Chromium, a temporary config on partner 6, deleted afterwards): with Allowed Domains = `http://allowed-partner.test`, the form inside an iframe on **example.com: blocked**, on **admin.allowed-partner.test: blocked**, on **allowed-partner.test and www.allowed-partner.test: shown**; the direct link: shown. Control with the list emptied: shown on example.com — so the block is the new rule.

## Open items
- A partner whose list does not include where they actually embed the form will now see it blocked there — check partners with a list (e.g. partner 17 lists `fourcorneracademy.com` but publishes on `admin.fourcorneracademy.com`; framing on the `admin.` subdomain is now refused unless added).
- Mark BUG-092 in the QA defect log In Progress, then Fixed after the stage retest.
