# Website pre-qualification — the lead and the converted borrower keep the business details (BUG-090)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-090** (OP-LEAD-16): a website pre-qualification (S-Corporation, Beauty/Salon, Florida) converted to a borrower had Entity Type and Industry empty, and the convert dialog asked for the State again although the pre-qual gave Florida.

Reproduced end to end (pre-qual → missing-info dialog → convert):
- `PreQualificationService` passed `entity_type` and `industry_type` to the website `LeadService::store()`, which builds the lead from a fixed list and **dropped both** — since the website pre-qual API was added (2026-03-11).
- The lead had **no country**, which conversion requires, so the dialog opened; choosing the country cleared the state, so it was asked again.
- The website's entity / industry options differ from the portal's ("LLC" vs "Limited Liability Company (LLC)", "Beauty, Salon & Spa Services" vs "Personal Services (Beauty, Fitness, Wellness)"), so even a copied value would not match a dropdown option.
- Company → Legal Business Name **was** copied (the `legal_business_name ?: company_name` fallback, since January). If stage shows it empty, stage is running older code.
- Business Start Date: the pre-qual asks "years in business" as a range, not a date — left empty rather than invented.

## Solution
- `App\Support\Lead\WebsiteBusinessOptions` maps each website answer to the portal's option (`config('funding_application.options')`, the lists the portal's dropdowns use); an answer already a portal option is kept; one with no match becomes **Other** with the answer as the note (e.g. "Cannabis / CBD Industry", "PLLC").
- `PreQualificationService` gives the lead the mapped entity and industry (with notes) and `Lead::DEFAULT_COUNTRY` ("United States" — the form asks for a US state of incorporation). The website `LeadService::store()` keeps `country`, `entity_type(_note)`, `industry_type(_note)` when given.
- Conversion (unchanged) then copies them to the borrower; with the country set the missing-info dialog no longer opens for these leads.
- Applies to pre-qualifications submitted from now on. Leads and borrowers saved before are left as they are (decided: no back-fill).

## Files changed
- `app/Support/Lead/WebsiteBusinessOptions.php` — new.
- `app/Models/Lead/Lead.php` — `DEFAULT_COUNTRY`.
- `app/Services/Global/Website/PreQualification/PreQualificationService.php` — mapped entity / industry and country on the lead.
- `app/Services/Global/Website/Lead/LeadService.php` — keeps those fields.
- Tests: `tests/Unit/Lead/WebsiteBusinessOptionsTest.php` (new), `tests/Feature/Website/WebsitePrequalLeadConversionTest.php` (new, 3).

## Tests
- All new tests failed first (class missing; lead without entity, industry, country), pass after: every website option maps to a portal option; the full flow gives the borrower company, entity, industry, state and country.
- `tests/Feature/Website`, `tests/Unit/Lead`, `tests/Feature/Lead`: passed. Full suite: see the session report.

## Open items
- Website leads and borrowers converted before this change keep their empty entity / industry / country (no back-fill, as decided); QA should retest with a new pre-qualification, not client 147.
- If the website's option lists change, add the new answers to `WebsiteBusinessOptions` (unmatched ones fall back to Other + note, so nothing is lost meanwhile).
- Business Start Date stays empty for website leads (no date in the form).
- Mark BUG-090 in the QA defect log In Progress, then Fixed after the stage retest.
