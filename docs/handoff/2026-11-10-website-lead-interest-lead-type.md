# Website leads: "What brings you here today?" decides the lead type

**Date:** 2026-11-10

## Problem
The website contact page asks "What brings you here today?" ("Explore becoming a client" / "Explore partnership opportunities") and sent the answer as `interest`. `POST /api/v1/website/leads` dropped it: it was not in `StoreLeadRequest`'s rules, and `LeadService` hard-coded `'lead_type' => 'Borrower'`. So someone asking about partnership was saved as a Borrower lead, and their answer was lost.

## Solution
- The website sends the lead type itself as `interest`: `Borrower` for "Explore becoming a client", `Partner` for "Explore partnership opportunities". These are the existing `Lead::BORROWER_TYPE_LEAD` / `Lead::PARTNER_TYPE_LEAD`; no new codes.
- `Lead` model: `WEBSITE_INTERESTS = [BORROWER_TYPE_LEAD, PARTNER_TYPE_LEAD]` and `leadTypeForInterest()`. `Partner` gives `Partner`; `Borrower` or no answer gives `Borrower`.
- `Global/Website/Lead/StoreLeadRequest`: `interest` is optional and must be `Borrower` or `Partner`, otherwise 422. It stays optional because the home page form does not ask it and pre-qualification uses the same `store()`.
- `Global/Website/Lead/LeadService::store()`: `lead_type` comes from `leadTypeForInterest()`, and `status` uses `Lead::STATUS_NEW_LEAD` instead of a literal.
- The website sends the value, not the label text, so the wording can change without breaking the API. It goes in `interest`, never `lead_type`: the API checks it against the two allowed values and sets `lead_type` itself.
- The public response (`Global/Website/Lead/LeadResource`) is unchanged and does not expose `lead_type`.

Partner leads already work in the admin panel (Leads filter, convert to partner, host-only ownership), so nothing else changes. Website leads always belong to the host.

## Files changed
- `app/Models/Lead/Lead.php`: `WEBSITE_INTERESTS` and `leadTypeForInterest()`.
- `app/Http/Requests/Api/V1/Global/Website/Lead/StoreLeadRequest.php`: `interest` rule.
- `app/Services/Global/Website/Lead/LeadService.php`: lead type from the interest; status constant.
- `tests/Feature/Website/WebsiteLeadInterestTest.php`: new.
- `tests/Unit/Lead/LeadTypeForInterestTest.php`: new.

## Testing
- New tests written first and failing before each change (first 6 of 8 with `client`/`partnership`; after the switch to `Borrower`/`Partner`, 5 of 8). All 8 pass now.
  - Feature: `Borrower` gives Borrower, `Partner` gives Partner (source Website, owned by the host), no answer gives Borrower. Any other value (`partnership`, `partner`, the label text) gives 422, and `lead_type` in the request is ignored.
  - Unit: the allowed values and the mapping.
- `tests/Feature/Website`, `tests/Unit/Lead`, `tests/Feature/Lead`, `tests/Integration/PreQualification`: 247 passed.
- Website side: `fch-website-react/docs/handoff/2026-11-10-contact-interest-lead-type.md`.

## Open items
- Leads saved before this change keep `lead_type = Borrower`, and their answer was never stored, so it cannot be backfilled.
- The answer itself is not stored, only the resulting `lead_type`. Add a column if the label is ever needed.
