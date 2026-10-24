# Shared format validation for ZIP, phone, SSN, EIN and DUNS

**Date:** 2026-10-15 · **Branch:** `bugfix/all-bugfix` (uncommitted)

## Problem
The API already had one rule class per format in `app/Rules` — `UsPostalCode` (5 digits), `UsPhoneNumber` (valid US/NANP number), `Ssn`, `BusinessEin` (9 digits, `XX-XXXXXXX`) and `DunsNumber` (9 digits) — but most requests did not use them. 65 fields across 30 requests validated with their own `max:20` / `max:255` / `digits:9` / `size:9` / loose regex, so the same field was accepted on one screen and refused on another:
- phone: only 2 of ~45 phone fields used `UsPhoneNumber`; the rest accepted `1234567890`, `555-0100` or, with `max:50`, anything;
- SSN: `digits:9` in the fund-application, co-borrower and embed-form requests, a loose regex on the admin client requests;
- EIN: free text (`max:20`) on leads, `max:100` on the partner / demo-request `tax_id`;
- DUNS: `size:9` (letters allowed) on the borrower profile, `max:20` on leads;
- ZIP: `max:20` / `max:255` on the admin client's `business_postal_code`.

`UsPhoneNumber` also passed a valid number wrapped in letters (`tel 813-391-3333`), because it only looked at the digits.

## Solution
Server-side only — the portals were not changed (agreed with the user; the API's 422 field errors are what the forms show).
- Every ZIP, phone, SSN, EIN and DUNS field in `app/Http/Requests` now validates with the shared rule. Decisions agreed with the user: **all** phone fields use `UsPhoneNumber`; partner and demo-request `tax_id` is an EIN; records already saved with a non-conforming value are refused on their next save until corrected (no data migration).
- `UsPhoneNumber` refuses anything but digits, spaces, `( ) . + -`, like `Ssn`/`BusinessEin` do.
- `Ssn::canonical()` (new): reduces a *writing* of an SSN (digits, spaces, hyphens) to its nine digits and leaves anything else as sent, so the rule still refuses letters. The four requests that used to demand `digits:9` (borrower and partner ownership info, borrower and partner co-borrower) now call it in `prepareForValidation()`, so they keep storing nine digits while also accepting `XXX-XX-XXXX`. The embed form used `preg_replace('/\D/', …)` there, which silently stripped letters; it now uses `Ssn::canonical()` for `ssn` and each `co_borrowers.*.ssn`.
- Kept on purpose: `SendTestSmsRequest` keeps its E.164 regex alongside `UsPhoneNumber` (the SMS provider needs `+1…`); contacts keep the `(XXX) XXX-XXXX` regex alongside `UsPhoneNumber`, because the contact number is stored exactly as sent (leads reformat 10 digits in `prepareForValidation()`, contacts do not).
- EIN / `tax_id` on leads and partners is still stored as sent (not reduced to digits), so their existing display is unchanged.
- Follow-up the same day (while backfilling tests at every level):
  - Employee `phone_number_1` / `phone_number_2` were missed by the first inventory (digits in the field name) and are now `UsPhoneNumber` too.
  - The ownership step (borrower and partner) took `co_borrowers` as an unchecked array, so a co-borrower's SSN/ZIP/phones saved there bypassed every rule and were stored raw. They are now checked in the request's `after()` hook (nullable — a co-borrower may be saved part-filled), and each co-borrower SSN goes through `Ssn::canonical()`. Not as `co_borrowers.*.x` rules: nested rules make `validated()` keep only the ruled keys, which dropped the co-borrower's names and stopped it being saved.

## Files changed
- `app/Rules/UsPhoneNumber.php` — refuses letters and other characters around a valid number.
- `app/Rules/Ssn.php` — new `canonical()`.
- 32 request classes under `app/Http/Requests/Api/V1/` — fields switched to the shared rules (old `max`/`regex`/`digits`/`size` dropped, `required`/`nullable`/`unique` kept); SSN `prepareForValidation()` added to the two ownership-info and two co-borrower requests; `SubmitEmbedFormRequest` SSN normalisation changed; the two ownership-info requests also check co-borrowers in `after()`.
- `tests/Feature/Validation/IdentifierFormatRuleWiringTest.php` (new) — 108 cases: every request field uses its shared rule (including the change-request modal's per-field `new_value`).
- `tests/Feature/Validation/SsnNormalisationTest.php` (new) — 11 cases for the SSN normalisation above.
- `tests/Unit/Validation/SsnTest.php`, `tests/Unit/EmbeddedForm/UsPhoneNumberTest.php` — new cases for `canonical()` and letters around a phone number.
- HTTP feature tests through the real endpoints (each sends values the rules refuse and asserts the API's exact message): `Borrower/BorrowerProfileApiTest` (business phone, DUNS), `Lead/LeadAndContactApiTest` (lead contact-info phone/business phone/EIN/DUNS/ZIP; contact mobile), `Partner/PartnerAdminApiTest` (phone, ZIP, tax id), `Website/PublicWebsiteApiTest` (website lead phone), `Employee/EmployeeApiTest` (all three phones), `FundApplication/BorrowerFundingApplicationStepsTest` and `PartnerFundingApplicationStepsTest` (owner and co-borrower formats; written SSN stored as nine digits, owner and co-borrower).
- `Employee/EmployeeApiTest`, `User/UserManagementApiTest` — random fixture phones (`'555' . random_int(1000000, …)`) could draw an exchange starting with 1, i.e. fail ~1 run in 9; now drawn from 2000000.
- 13 feature tests under `tests/Feature/` — fixture phone numbers that are not valid US numbers (`(555) 123-4567`, `(555) 010-0100`, `5550100`, …) replaced with valid ones in input and assertion alike; deliberate bad values (`call me`, `555-`, `12`) kept.

## Testing
- New tests written first and seen failing (65 of 104 wiring cases, 2 phone cases, 7 SSN `canonical()` cases, 6 SSN normalisation cases), then passing.
- Full suite `php artisan test`: **2620 passed, 1 skipped, 0 failed** (the first run after the change had 38 failures, all 422s from the invalid fixture phone numbers above).
- Follow-up tests written first and seen failing: 4 employee wiring cases; the co-borrower format and co-borrower SSN cases (missing key / SSN stored with hyphens). The other new HTTP cases cover rules already in place and passed on first run.
- Full suite after the follow-up: **2635 passed, 1 skipped, 0 failed**.
- E2E, against the local API:
  - borrower `e2e/sidebar/profile/business-details-format-errors.spec.ts` (new) — an 8-digit D-U-N-S is refused by the real API, the message shows on the field, nothing saved: **pass**.
  - admin `e2e/topbar/profile/profile-format-errors.live.spec.ts` (new) — a non-US phone on the host's own profile is refused by the real API, shown on the field, nothing saved: **pass** (with `topbar.live.spec.ts`, 6/6).
  - borrower `profile-save.spec.ts` **fails** on the local E2E account's data, not on this change: the account holds only the last 4 SSN digits (E2E-015, "still needs restoring"), so the existing `ssn` required-unless-on-file rule refuses the save.
- No portal source changed, so no portal unit/integration test applies to this change.

## Open items
- **Existing data:** any stored non-US phone, ZIP+4, 8-digit DUNS or free-text tax id will now be refused when that record is next saved. Worth a read-only query on stage/production before release to see how many rows that is.
- Old custom messages for the dropped rules (e.g. `phone_number.regex`, `duns_number.size`, `ssn.digits` in several requests) are now unreachable; harmless, left in place.
- `UsPhoneNumber` still reports `embed_form.phone_invalid`; the text is generic, but the key could move to `common.` for clarity.
- Restore the local E2E borrower's full SSN (E2E-015) so `profile-save.spec.ts` can pass again.
- `FCH\Common\Lead\StoreLeadRequest` and `UpdateLeadRequest` are not used by any controller (leads save through `StoreLeadDetailsRequest` / `UpdateLeadDetailsRequest` / `UpdateLeadContactInfoRequest`); wired anyway, candidates for removal.
- `database/seeders/DatabaseSeeder.php` has an unrelated uncommitted edit from earlier (two commented-out lines removed) — not part of this change.
