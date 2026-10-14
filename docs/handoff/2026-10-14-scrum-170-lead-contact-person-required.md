# SCRUM-170 — Contact Person required on lead details

**Date:** 2026-10-14
**Branch:** testing-local-environment (SCRUM-170 merged in)
**Author:** arafat-invento

## Problem
A lead could be created or edited from the admin/partner portal's Lead Details tab without a contact person, and the lead notification email labelled the contact person "Name" and listed the company below it.

## Solution
- API: `contact_person` is `required|string|max:255` on `POST v1/common/leads/details` and `POST v1/common/leads/{id}/details` (message `lead.contact_person_required`). The contact-info endpoint already required it.
- Email: the lead notification shows Company first, then "Contact Person".
- Admin portal (`Fch-admin-frontend-react`, `src/pages/leads/LeadDetails.jsx`): a Contact Person field on the Lead Details tab, client-side validation (contact person on both tabs; email and phone on the Contact Info tab), and `contact_person` is sent in the details payload.

Behaviour change: saving the Lead Details tab of an **existing** lead that has no contact person is now refused until one is entered. The public website lead endpoint (`v1/website/leads`) is not affected.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Common/Lead/StoreLeadDetailsRequest.php`, `UpdateLeadDetailsRequest.php` — `contact_person` required
- `resources/views/emails/lead/notification.blade.php` — Company / Contact Person order and label

## Tests
- Added: `tests/Feature/Lead/LeadContactRequiredTest.php` — store/update refuse a missing contact person
- Updated: `tests/Feature/Lead/LeadAndContactApiTest.php` — the valid `leadDetails()` payload now includes `contact_person` (3 tests had been failing with 422)
- Admin e2e updated: `e2e/sidebar/leads/leads.spec.ts` — create tests fill Contact Person; new test that Save is blocked and nothing is posted without it; the create payload asserts `contact_person`
- Suites run: backend full suite — 2438 passed, 1 skipped; admin vitest — 1212 passed (2 expected-fail); admin leads e2e — 35 passed

## Open / follow-up
- Existing leads with an empty `contact_person` will need one entered the next time their details are saved.
