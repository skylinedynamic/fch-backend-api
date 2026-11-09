# Embedded Form: Allowed Domains stored as hosts, and every refusal names the entry (BUG-035, round 2)

**Date:** 2026-11-02
**Author:** awlad

## Problem
QA round 2 **BUG-035** (R2-OP-EMB-01, Owner portal › Embedded Form › Configuration › Allowed Domains):
- **Bare host names refused.** `x.com`, `*.x.com` and `localhost` were refused with "The allowed_domains.2 field must be a valid URL." (`allowed_domains.* => url`). Yet the matching code (`normalizeHost`) already reads bare hosts.
- **Stored as typed.** Entries were kept exactly as typed, so `https://x.com/path` was saved with its path.
- **Repeats let in.** Nothing on the server refused the same domain twice.

## Solution
Product owner's decisions:
- Store the bare host.
- An entry typed with `http://` allows the site over **both** http and https.
- Refuse `localhost`.

Changes:
- **New `App\Support\EmbedForm\AllowedDomain`:**
  - `host()`: lowercase, without scheme, path, query, credentials, port, trailing dot or `www.`. Null for another scheme.
  - `normalize()`: the stored form. `x.com`, or `http://x.com` when typed with http.
  - `allowsHttp()`, `isWildcard()`, `isLocalhost()`, `isValidHost()` (dot-separated labels with a letter or `xn--` TLD; rules out single names and IPs).
- **`UpdateEmbedFormConfigRequest`:**
  - `prepareForValidation` stores each entry normalised.
  - Each entry is refused if it is a wildcard, localhost, or not a domain.
  - `withValidator` refuses a repeat by host, so `x.com`, `https://X.com/other`, `www.x.com` and `http://x.com` are all the same.
  - Every message names the entry **as typed**, under `allowed_domains.<index>`.
- **`EmbedFormService::frameAncestors()`:** every entry allows https. One stored with `http://` allows http too, each with and without `www.`. Previously an `http://` entry allowed http only.
- **`lang/en/embed_form.php`:** `allowed_domain_wildcard`, `_localhost`, `_invalid`, `_duplicate`.
- **No change needed** to `isOriginAllowed()` (it compares hosts) or to data already stored. Legacy entries such as `https://skylinedynamic.dev` are still read correctly, and are normalised on the next save.

## Files changed
- `app/Support/EmbedForm/AllowedDomain.php`: new.
- `app/Http/Requests/Api/V1/FCH/Common/EmbedForm/UpdateEmbedFormConfigRequest.php`: normalise, rules, repeat check.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `frameAncestors` schemes.
- `lang/en/embed_form.php`: four messages.
- `tests/Feature/EmbedForm/AllowedDomainsTest.php`: new, 6 tests (bare-host storage, `http://` kept, wildcard, localhost, non-domains, repeats however typed; the stored list unchanged on refusal).
- `tests/Unit/EmbeddedForm/AllowedDomainTest.php`: new, 5 tests.
- `tests/Feature/EmbedForm/EmbedFormFramingTest.php`: an `http://` entry now also allows https; +1 (a bare host is https only).
- `tests/Feature/EmbedForm/EmbedFormSubmissionsApiTest.php`: `an_allowed_domain_must_be_a_url` renamed `..._must_be_a_domain`. Same expectation.

## Testing
- The 6 new feature tests and the changed framing test failed against the old code (run with the change stashed), then passed.
- `tests/Feature/EmbedForm`, `tests/Unit/EmbeddedForm`, `SecurityHeadersTest`: 343 passed. New unit tests: 5 passed.

## Follow-up, same day: `notification_email` removed
**Problem:** the config saved `notification_email`, but new-submission mail goes to the partner's own email (`$partner->email`), and the portal never sends the field.

**Solution (product owner: remove it):**
- New migration `2026_11_02_000001_drop_notification_email_from_embedded_form_configs`. It drops the column; `down()` re-adds it.
- Removed from:
  - `UpdateEmbedFormConfigRequest` (rule);
  - `EmbedFormService` (`getOrCreateConfig`, `updateConfig`);
  - `EmbedFormConfigResource`;
  - `EmbedFormController`'s placeholder config.

**Tests:**
- `EmbedFormSubmissionsApiTest::the_form_has_no_notification_email_setting` (new; failed first): no column, not in the config response, and a stray value is neither validated nor stored.
- `EmbedFormNotificationMailTest::a_stored_notification_email_is_ignored` removed. It checked that a stored value did not redirect mail, which can no longer happen without the column. The recipient rule is still covered by `the_partners_email_column_is_the_address_used`. The class docblock is updated.
- Embed form tests: 348 passed.

**Deploy:** run `php artisan migrate`. Locally the migration was not run against the dev database. The code no longer reads the column, so it works either way.

## Open items
- The Owner portal (`react-fch`) shows the refusals under the field; see its handoff of the same date.
- QA sheet BUG-035 → Fixed in build, pending stage retest.
