# R3-BUG-004: a lead's SSN and EIN are masked, with a logged reveal

Date: 2026-11-14

## Problem

`GET /api/v1/common/leads/{id}`, and the lead list (`POST leads/fetch-leads`), returned the lead's full 9-digit SSN unmasked.
- `LeadResource` sent `'ssn' => $this->ssn`. Since `2026_10_19_120000_encrypt_ssn_on_leads_table`, that is the full number decrypted.
- The Business EIN was sent in full as well.
- The host opens every partner's leads (`Lead::visibleTo()`), so the host had every partner's lead SSNs.
- Clients have been masked since BUG-014: the number shows masked, and a logged reveal gives it in full. That rule was never applied to leads.

## Solution

Leads follow the client rule (BUG-014):
- **Masked in every lead response.** `LeadResource` sends `ssn` as `***-**-6789` (`Ssn::mask`) and `business_ein` as `**-***4321` (`BusinessEin::mask`). This covers details, list, create and update.
- **Reveal: `POST /api/v1/common/leads/{id}/reveal {field: ssn|business_ein}`.**
  - Anyone who can open the lead may reveal: the lead's partner team, and the host. Others get 404.
  - Each reveal is logged on the lead ("SSN Viewed" / "Business EIN Viewed", internal). The log names who looked, never the number.
- **A mask sent back changes nothing.** `UpdateLeadContactInfoRequest` and `UpdateLeadRequest` drop an `ssn` / `business_ein` that is a mask, so the number on file stays.
  - A number typed in still replaces it.
  - `null` still clears the SSN.
- **Conversion is unchanged.** It reads the lead in the database (`$lead->ssn`), not the API, so the new client still gets the full SSN. `LeadAndContactApiTest::converting_a_lead_carries_its_full_ssn_to_the_new_client` covers it.
- **Host visibility is unchanged.** The host still opens every partner's leads, as with clients. With the numbers masked and each reveal logged, that is no longer an exposure.

## Files changed

- `app/Http/Resources/FCH/Lead/LeadResource.php`: `ssn` and `business_ein` are masked.
- `app/Http/Requests/Api/V1/FCH/Common/Lead/RevealLeadFieldRequest.php` (new): the field to reveal.
- `app/Http/Requests/Api/V1/FCH/Common/Lead/UpdateLeadContactInfoRequest.php`, `UpdateLeadRequest.php`: drop a mask sent back.
- `app/Services/FCH/Common/Lead/LeadService.php`: `REVEALABLE_FIELDS`, `revealField()` (logs the look).
- `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`: `reveal()`.
- `routes/common.php`: `POST leads/{id}/reveal`.
- Tests:
  - `tests/Feature/Lead/LeadSensitiveFieldRevealTest.php` (new, 18). It covers:
    - details and list are masked, for the owner and for the host on another partner's lead
    - null numbers stay null
    - reveal of each field, and the log without the number
    - the host's reveal is logged
    - a number not on file reveals as null
    - another partner's lead is 404
    - only `ssn` / `business_ein` can be revealed
    - a mask sent back keeps both numbers (contact info and details)
    - a new number replaces the old one
  - `tests/Feature/Lead/LeadAndContactApiTest.php`: `a_leads_ssn_is_the_full_number_kept_encrypted` now expects `data.ssn` masked.

## Testing

- **Written first:** `LeadSensitiveFieldRevealTest`. 12 failed before the change. The 4 that passed (no numbers, another partner's 404, details save, a new number) pin behaviour that stays.
- **`tests/Feature/Lead`:** 96 tests, all passed.
- **Full suite:** 5,527 tests, all passed (2 skipped).

## Open items

- **Deploy together, in the same release:** deploy together with the admin panel change of the same date (`Fch-admin-frontend-react/docs/handoff/2026-11-14-r3-bug-004-lead-ssn-masked.md`).
  - The old lead form runs the masked SSN through `formatSSN` and sends `6789`. That isn't a mask, so it isn't dropped: the API answers 422.
  - The same happens to the EIN. Saving Contact Info on a lead with an SSN or EIN on file fails until the new form is out.
  - No data is lost either way.
- **Staging data:** QA's test SSN on Wanda's lead 213 is still stored. It is masked now.
