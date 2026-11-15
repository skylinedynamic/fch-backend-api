# Embed form: record the page an application came from

**Date:** 2026-11-09
**Branch:** bugfix/iframe-application-website
**Author:** Awlad Hossain

## Problem
SCRUM-435 S435-BUG-05: an application from the website's `/apply` page could not be told apart from one sent through the direct link. QA found `origin_url` = `https://skylinedynamic.dev/` on #36 (`/apply`) and on #29 and #30 (direct link).

`origin_url` stored the submit request's `Referer`. The form sends that request itself, from its own address (`/form/{key}` on the website's host), to the API on another host. So the header most likely named the form's host every time, never the page around it. This comes from reading the code; it was not checked on stage.

QA also reported that the detail page shows no source. The "Source Page" field does exist, under Submission Metadata.

## Solution
Awlad chose to fix `origin_url` rather than add a `source` column. No migration.

- **The form:** when framed, it sends `origin_page` with its submission, from `document.referrer`, or the origin the framing page gave in its `hello` when there is no referrer.
- **The request:** `SubmitEmbedFormRequest` cleans the value with the new `FramingPage::clean()`. A bad value becomes null and never refuses the application.
- **The service:** `origin_url` now comes from `origin_page`, not the `Referer` header.
- **What is kept:** only http or https addresses, up to 500 characters, without the query, fragment or credentials. The value is shown to staff as a link, and a query can hold personal data.

**Behaviour change:**

| Where the form is | `origin_url` before | `origin_url` now |
|---|---|---|
| Website `/apply` | the form's host | the full `/apply` address |
| Another site | the form's host | that site's origin only (browsers give no more) |
| Direct link | the form's host | empty |

Existing rows are unchanged. The allow-list check still reads the `Origin` and `Referer` headers; it does not use this value.

## Files changed
- `app/Support/EmbedForm/FramingPage.php`: new, cleans the address.
- `app/Http/Requests/Api/V1/Global/Website/EmbedForm/SubmitEmbedFormRequest.php`: cleans and allows `origin_page`.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: stores it in `origin_url`.
- `resources/views/embed-form/index.blade.php`: `framingPage()`, sent on submit.
- `tests/Unit/EmbeddedForm/FramingPageTest.php`, `tests/Feature/EmbedForm/EmbedFormSourcePageTest.php`: new.
- `fch-website-react/e2e/apply-source-page.spec.ts`: new. `e2e/support/apply.ts`: can serve the form from the website's address, as the edge proxy does.
- `react-fch/tests/pages/embed-form/EmbedFormSubmissionDetails.test.tsx`, `react-fch/e2e/embed-form-submission-business-funding.spec.ts`: "Source Page" cases. The admin's code did not change.

## Tests
- Added: `FramingPageTest` (17 cases) and `EmbedFormSourcePageTest` (6). Both failed before the code existed.
- `EmbedFormSourcePageTest` calls `storeSubmission()` and the request's clean-up directly. No test posts a complete application through `POST /v1/embed/submit/{key}`, so that route's whole path is not covered here.
- Suites run:
  - fch-api: `tests/Feature/EmbedForm`, `tests/Unit/EmbeddedForm` and `SsnNormalisationTest`, 460 passed. The whole suite was not run.
  - fch-website-react: Playwright, 22 passed (Chromium); typecheck clean.
  - react-fch: `tests/pages/embed-form`, 69 passed; `embed-form-submission-business-funding.spec.ts`, 7 passed with `--workers=1`; `npm run typecheck` clean.
  - `npm run typecheck:e2e` in react-fch reports errors in `e2e/sidebar/clients/client-new-email.live.spec.ts`, a file this change does not touch.

## Open / follow-up
- **A real submission on stage is needed:** confirm a new application from `/apply` shows the `/apply` address under Source Page. The E2E mocks the submit and simulates the edge proxy.
- **Not trustworthy:** the value comes from the browser and can be forged. Treat it as a label only.
- **No filter:** the list cannot be filtered by source. That would need the `source` column (option 2).
- Not committed.
