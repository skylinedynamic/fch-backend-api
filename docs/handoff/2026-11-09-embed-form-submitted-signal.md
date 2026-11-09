# Embed form: tell the framing page about a confirmed submission

**Date:** 2026-11-09
**Branch:** bugfix/iframe-application-website
**Author:** Awlad Hossain

## Problem
SCRUM-435 S435-BUG-01: the website could not count confirmed submissions in GA4. The form runs in an iframe on the API's domain, and nothing told the page around it that a submission had succeeded.

## Solution
In the submit handler's success branch only, the form now calls `postToParent({ type: 'submitted' })`, using the existing message channel. The message has no field values and no Reference ID. A rejected or failed submit sends nothing, and a form opened by its direct link has no parent, so it sends nothing either.

**Behaviour another site will notice:** any page that embeds the form, including partner sites, now receives this message. `embed.js` ignores it, so nothing changes on those pages unless a partner listens for it.

## Files changed
- `resources/views/embed-form/index.blade.php`: sends the message on success; comments updated, since more than layout numbers are now sent.
- `public/form/assets/embed.js`: header comment only.
- `tests/Feature/EmbedForm/EmbedFormParentBridgeTest.php`: see below.
- In fch-website-react: the listener and GA4 events. See `fch-website-react/docs/handoff/2026-11-09-apply-ga4-events.md`.

## Tests
- Added to `EmbedFormParentBridgeTest`:
  - `only_a_confirmed_submission_tells_the_framing_page_and_it_sends_no_data` (failed before the change)
  - `the_embed_script_on_partner_sites_only_acts_on_height_and_scroll`
- These read the rendered page's script as text, like the rest of that test class. The browser behaviour is covered by the website repo's Playwright spec.
- No Unit or Integration test was added: the change is a line of inline script in a Blade view, with no PHP class to test.
- Suites run: `tests/Feature/EmbedForm`, 213 passed. The whole API suite was not run.

## Open / follow-up
- A real full submission on stage has not been checked yet; see the website handoff.
- Not committed.
