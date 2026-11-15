# R3-BUG-040: a white-label portal's served HTML carries the host's favicon

Date: 2026-11-16

## Problem

QA (R3-CP-GLOB-09-R2) fetched `https://wanda-borrower.skylinedynamic.dev/login` as raw HTML, before any JavaScript ran:
- The title was Wanda's, which is correct.
- The icon was `/assets/Favicon-BzYqrv88.png`, the host's packaged favicon.

QA reported that Wanda's icon only arrived after the app loaded.

Checking the stage HTML showed it actually carries **both** icons:
- line 16: the host's packaged icon, from `index.html`
- line 31: Wanda's icon, from the API's portal-meta SSI fragment

Browsers take the later one, so the tab is mostly right. But the host's icon is in every partner's HTML. Previews and crawlers that take the first icon show the host's, and some browsers can flash it.

**Cause:** the packaged `<link rel="icon">` sat outside the SSI stub block. The old reason was that Vite fingerprints its path, so the API could not name it. The API emitted a favicon only when the tenant had one.

## Solution

- **API (`PortalMeta`):** the fragment always names exactly one icon: the tenant's favicon, else `PortalMeta::PACKAGED_FAVICON` (`/favicon.png`). The path is relative, so it opens on whichever portal asked.
- **Both portals:**
  - `public/favicon.png` is a fixed-path copy of `assets/images/Favicon.png`. Vite copies it to the build root without a fingerprint.
  - `index.html` moves `<link rel="icon" href="/favicon.png" />` **inside** the `portal_meta_default` stub.

The result:
- When SSI works, the fragment replaces the stub, and a partner's HTML has only its own icon.
- When SSI is off or the call fails, the stub's packaged icon is served.
- The branding script still updates the (now single) icon tag.

## Files changed

- **fch-api:**
  - `app/Support/Branding/PortalMeta.php`: `PACKAGED_FAVICON`; the favicon is never null.
  - `tests/Feature/Branding/PortalMetaTest.php`: "no favicon anywhere" now expects `<link rel="icon" href="/favicon.png">`; new test that a partner portal names exactly one icon, its own.
- **client-fch** and **Fch-admin-frontend-react**:
  - `public/favicon.png` (new)
  - `index.html`: the icon moved into the stub; comment updated
  - `tests/index-html.test.ts`: the icon is inside the stub, nowhere outside it, at `/favicon.png`, and the file exists in `public/`

## Testing

- **Seen failing first:** in each portal, 3 of the 6 index.html tests. In the API, the "no favicon anywhere" test.
- **After the fix:**
  - `fch-api` `tests/Feature/Branding` and `tests/Unit/Branding`: 74 passed
  - `index-html.test.ts` and `portalMeta.test.ts`: 17 passed in each portal
- **Production builds** (`vite build`) of both portals:
  - `favicon.png` is at the build root
  - the icon is inside the stub
  - the SSI markers are intact
- **Full suite:** see the PR / session notes.

## Open items

- **Retest on stage after deploy:** the raw HTML of a WL domain should hold one `rel="icon"`, the partner's.
- **Deploy order:** deploy the API change together with the portals. If the new `index.html` goes out before the API, a tenant with no favicon would briefly get no icon from the fragment; tenants with a favicon are unaffected.
- R3-BUG-040 is assigned to Arafat in the defect log. Fixed here at the user's request.
